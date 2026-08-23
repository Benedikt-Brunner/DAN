<?php

declare(strict_types=1);

namespace Dan\Probe\Synthetic;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * First synthetic definition: a flat entity with a JSON payload, used to
 * exercise JSON field handling - one of the places where the DAL emits
 * engine-dependent SQL (MySQL vs MariaDB JSON paths).
 *
 * TODO: further synthetic definitions for targeted edge-case coverage:
 *  - parent/child inheritance with inherited fields
 *  - translated entity with multi-language fallback chains
 *  - deep to-many association chains (a -> b -> c -> d)
 *  - many-to-many with heavy mapping tables
 */
final class SyntheticBlobDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'dan_synthetic_blob';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new Required()),
            (new JsonField('payload', 'payload', [
                new StringField('segment', 'segment'),
                new IntField('score', 'score'),
                new BoolField('active', 'active'),
            ]))->addFlags(new Required()),
            (new IntField('rank', 'rank'))->addFlags(new Required()),
        ]);
    }
}
