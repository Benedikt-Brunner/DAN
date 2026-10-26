<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Fingerprint;

use Dan\Lib\Protocol\DatasetAspect;
use Dan\Lib\Protocol\DatasetFingerprint;
use Dan\Lib\Protocol\Tier;
use Dan\Probe\Seeding\Dataset\DeterministicId;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Checksums the dataset the seeder owns, aspect by aspect, straight in SQL.
 * Every aspect selects the values scenarios can observe - ids, numbers,
 * names, prices, mapping pairs - and nothing physical or unstable: no
 * timestamps, no auto-increments, no installation data. Rows are identified
 * by the seeder's own markers (DAN product numbers and names, its tax id,
 * its own table), so a Shopware version installing different defaults does
 * not register as a dataset difference. Aggregates are order-independent,
 * so the checksum is independent of row layout and index order.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class DatasetFingerprinter
{
    private const DAN_PRODUCT_NUMBERS = 'DAN-%';
    private const DAN_CATEGORY_NAMES = 'DAN Category %';

    public function __construct(
        private Connection $connection,
    ) {}

    public function fingerprint(Tier $tier): DatasetFingerprint
    {
        $parameters = [
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'systemLanguage' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'taxId' => Uuid::fromHexToBytes((string) DeterministicId::create('tax:default')),
            'grossPath' => sprintf('$.c%s.gross', Defaults::CURRENCY),
            'netPath' => sprintf('$.c%s.net', Defaults::CURRENCY),
            'productNumbers' => self::DAN_PRODUCT_NUMBERS,
            'categoryNames' => self::DAN_CATEGORY_NAMES,
        ];

        $aspects = [];
        foreach (self::aspectQueries() as $name => $rowsQuery) {
            $aspects[] = $this->checksum(name: $name, rowsQuery: $rowsQuery, parameters: $parameters);
        }

        return new DatasetFingerprint(tier: $tier, aspects: $aspects);
    }

    /**
     * Each query yields one column `x`: the observable values of one row,
     * joined. The aggregate below turns the rows into a count plus two
     * order-independent CRC32 aggregates (sum and xor), which together
     * change whenever any row's values change.
     *
     * @return array<string, string>
     */
    private static function aspectQueries(): array
    {
        return [
            'tax' => <<<'SQL'
                SELECT CONCAT_WS('|', HEX(`id`), `name`, `tax_rate`) AS x
                FROM `tax`
                WHERE `id` = :taxId
                SQL,
            'category' => <<<'SQL'
                SELECT CONCAT_WS('|', HEX(`ct`.`category_id`), `ct`.`name`) AS x
                FROM `category_translation` `ct`
                WHERE `ct`.`language_id` = :systemLanguage
                  AND `ct`.`category_version_id` = :liveVersion
                  AND `ct`.`name` LIKE :categoryNames
                SQL,
            'product' => <<<'SQL'
                SELECT CONCAT_WS('|',
                    HEX(`p`.`id`), `p`.`product_number`, `p`.`stock`, HEX(`p`.`tax_id`),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(`p`.`price`, :grossPath)) AS DECIMAL(12, 2)),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(`p`.`price`, :netPath)) AS DECIMAL(12, 2))
                ) AS x
                FROM `product` `p`
                WHERE `p`.`version_id` = :liveVersion
                  AND `p`.`product_number` LIKE :productNumbers
                SQL,
            'product.name' => <<<'SQL'
                SELECT CONCAT_WS('|', HEX(`t`.`product_id`), `t`.`name`) AS x
                FROM `product_translation` `t`
                INNER JOIN `product` `p` ON `p`.`id` = `t`.`product_id` AND `p`.`version_id` = `t`.`product_version_id`
                WHERE `t`.`language_id` = :systemLanguage
                  AND `p`.`version_id` = :liveVersion
                  AND `p`.`product_number` LIKE :productNumbers
                SQL,
            'product.categories' => <<<'SQL'
                SELECT CONCAT_WS('|', HEX(`pc`.`product_id`), HEX(`pc`.`category_id`)) AS x
                FROM `product_category` `pc`
                INNER JOIN `product` `p` ON `p`.`id` = `pc`.`product_id` AND `p`.`version_id` = `pc`.`product_version_id`
                WHERE `p`.`version_id` = :liveVersion
                  AND `p`.`product_number` LIKE :productNumbers
                SQL,
            'synthetic-blob' => <<<'SQL'
                SELECT CONCAT_WS('|',
                    HEX(`id`), `name`,
                    JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.segment')),
                    JSON_EXTRACT(`payload`, '$.score'),
                    JSON_EXTRACT(`payload`, '$.active'),
                    `rank`
                ) AS x
                FROM `dan_synthetic_blob`
                SQL,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function checksum(string $name, string $rowsQuery, array $parameters): DatasetAspect
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS `rows`, COALESCE(SUM(CRC32(`x`)), 0) AS `sum`, COALESCE(BIT_XOR(CRC32(`x`)), 0) AS `xor` FROM (%s) AS `fingerprint`',
            $rowsQuery,
        );
        // Only the parameters the query mentions may be bound.
        $bound = array_filter($parameters, fn (string $parameter): bool => str_contains($rowsQuery, ':' . $parameter), \ARRAY_FILTER_USE_KEY);
        $row = $this->connection->fetchAssociative($sql, $bound);
        if ($row === false || !is_numeric($row['rows'] ?? null) || !is_numeric($row['sum'] ?? null) || !is_numeric($row['xor'] ?? null)) {
            throw new RuntimeException(sprintf('Could not fingerprint dataset aspect "%s".', $name));
        }

        return new DatasetAspect(
            name: $name,
            rows: (int) $row['rows'],
            checksum: sprintf('%s-%s', (string) $row['sum'], (string) $row['xor']),
        );
    }
}
