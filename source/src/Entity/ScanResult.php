<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ScanResultRepository;
use App\Entity\Site;

/**
 * @ORM\Table(name="scan_result")
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass=ScanResultRepository::class)
 */
class ScanResult
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_CLEAN = 'clean';
    public const STATUS_INFECTED = 'infected';
    public const STATUS_FAILED = 'failed';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="Site")
     * @ORM\JoinColumn(name="site_id", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    private $site;

    /**
     * @ORM\Column(type="string", length=32, nullable=false)
     */
    private $status = self::STATUS_PENDING;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0})
     */
    private $infectedCount = 0;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $path;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $output;

    /**
     * @ORM\Column(type="datetime", nullable=false)
     */
    private $startedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $finishedAt;

    public function __construct()
    {
        $this->startedAt = new \DateTime('now');
    }

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?Site { return $this->site; }
    public function setSite(Site $site): void { $this->site = $site; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }

    public function getInfectedCount(): int { return (int) $this->infectedCount; }
    public function setInfectedCount(int $count): void { $this->infectedCount = $count; }

    public function getPath(): ?string { return $this->path; }
    public function setPath(?string $path): void { $this->path = $path; }

    public function getOutput(): ?string { return $this->output; }
    public function setOutput(?string $output): void { $this->output = $output; }

    public function getStartedAt(): ?\DateTime { return $this->startedAt; }
    public function setStartedAt(\DateTime $dt): void { $this->startedAt = $dt; }

    public function getFinishedAt(): ?\DateTime { return $this->finishedAt; }
    public function setFinishedAt(?\DateTime $dt): void { $this->finishedAt = $dt; }

    /**
     * @ORM\PrePersist
     */
    public function prePersist(): void
    {
        if (null === $this->startedAt) {
            $this->startedAt = new \DateTime('now');
        }
    }
}
