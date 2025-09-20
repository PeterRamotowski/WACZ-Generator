<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250920170704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE crawled_pages (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(2000) NOT NULL, title VARCHAR(500) DEFAULT NULL, depth INT NOT NULL, http_status_code INT NOT NULL, content_type VARCHAR(255) DEFAULT NULL, content_length INT DEFAULT NULL, status VARCHAR(50) NOT NULL, error_message LONGTEXT DEFAULT NULL, content LONGTEXT DEFAULT NULL, crawled_at DATETIME NOT NULL, response_time INT DEFAULT NULL, headers JSON DEFAULT NULL, wacz_request_id INT NOT NULL, INDEX IDX_B7D824B36CAA9E3A (wacz_request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wacz_requests (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(2000) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, max_depth INT NOT NULL, max_pages INT NOT NULL, crawl_delay INT NOT NULL, status VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, file_path VARCHAR(500) DEFAULT NULL, file_size INT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE crawled_pages ADD CONSTRAINT FK_B7D824B36CAA9E3A FOREIGN KEY (wacz_request_id) REFERENCES wacz_requests (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE crawled_pages DROP FOREIGN KEY FK_B7D824B36CAA9E3A');
        $this->addSql('DROP TABLE crawled_pages');
        $this->addSql('DROP TABLE wacz_requests');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
