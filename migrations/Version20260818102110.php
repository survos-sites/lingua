<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records which locale a Target was translated FROM when that was not the source locale.
 *
 * Null = direct from Source::\$text. 'en' = the English-hub route, where the row was built from
 * the stored source->English translation instead of making the engine pivot through an English
 * we never see. See App\\Message\\TranslateBatchMessage.
 *
 * NOTE: doctrine:migrations:diff again emitted `ALTER TABLE target DROP pending_steps`, and it
 * has again been removed by hand. It is pre-existing drift (column in the database, absent from
 * the mapping) and dropping a populated column is destructive and unrelated to this change. It
 * needs its own migration and its own decision; until then schema:validate keeps reporting the
 * database as out of sync.
 */
final class Version20260818102110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target.pivot_locale (English-hub provenance)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE target ADD pivot_locale VARCHAR(6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE target DROP pivot_locale');
    }
}
