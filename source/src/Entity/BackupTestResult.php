<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="backup_test_result")
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity()
 */
class BackupTestResult
{
    public const STATUS_PASS = "pass";
    public const STATUS_FAIL = "fail";
    public const STATUS_SKIP = "skip";

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;
    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;
    /**
     * @ORM\Column(type="string", length=255, nullable=false)
     */
    private $domainName;
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $backupFile;
    /**
     * @ORM\Column(type="string", length=16, nullable=false)
     */
    private $status;
    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $sizeBytes;
    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $durationSeconds;
    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $message;

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): ?\DateTime { return $this->createdAt; }
    public function setCreatedAt(\DateTime $d): void { $this->createdAt = $d; }
    public function getDomainName(): ?string { return $this->domainName; }
    public function setDomainName(string $v): void { $this->domainName = $v; }
    public function getBackupFile(): ?string { return $this->backupFile; }
    public function setBackupFile(?string $v): void { $this->backupFile = $v; }
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function getSizeBytes(): ?int { return $this->sizeBytes; }
    public function setSizeBytes(?int $v): void { $this->sizeBytes = $v; }
    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $v): void { $this->durationSeconds = $v; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $v): void { $this->message = $v; }

    /**
     * @ORM\PrePersist
     */
    public function prePersist(): void
    {
        if (null === $this->createdAt) {
            $this->createdAt = new \DateTime('now');
        }
    }
}
