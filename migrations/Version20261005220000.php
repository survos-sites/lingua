<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261005220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Translation worker jobs: request identity, input snapshot, result and provenance.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE translation_job (status VARCHAR(16) NOT NULL, dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, translated_text TEXT DEFAULT NULL, outcome VARCHAR(16) DEFAULT NULL, provenance JSON DEFAULT '{}' NOT NULL, metrics JSON DEFAULT '{}' NOT NULL, error JSON DEFAULT '{}' NOT NULL, duplicate_results INT DEFAULT 0 NOT NULL, request_id VARCHAR(36) NOT NULL, profile VARCHAR(100) NOT NULL, source_locale VARCHAR(6) NOT NULL, target_locale VARCHAR(6) NOT NULL, input_text TEXT NOT NULL, source_hash VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, target_key VARCHAR(32) NOT NULL, PRIMARY KEY (request_id))");
        $this->addSql('CREATE INDEX translation_job_target_status_idx ON translation_job (target_key, status)');
        $this->addSql('CREATE INDEX IDX_DB94F6074580895C ON translation_job (target_key)');
        $this->addSql('ALTER TABLE translation_job ADD CONSTRAINT FK_DB94F6074580895C FOREIGN KEY (target_key) REFERENCES target (key) ON DELETE CASCADE NOT DEFERRABLE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE translation_job'); }
}
