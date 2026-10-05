<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261005171000 extends AbstractMigration
{
    public function getDescription(): string { return 'Allow named model profiles and preserve resolved translation provenance.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE target ALTER COLUMN engine TYPE VARCHAR(100)');
        $this->addSql("ALTER TABLE target ADD provenance JSON NOT NULL DEFAULT '{}'");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Profile names and translation provenance must be retained.'); }
}
