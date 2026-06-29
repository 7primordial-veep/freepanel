<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MarketplaceAppRepository;

/**
 * @ORM\Table(name="marketplace_app")
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass=MarketplaceAppRepository::class)
 */
class MarketplaceApp
{
    public const TYPE_WORDPRESS = 'wordpress';
    public const TYPE_GHOST = 'ghost';
    public const TYPE_NEXTCLOUD = 'nextcloud';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_COMING_SOON = 'coming-soon';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=128, unique=true, nullable=false)
     */
    private $slug;

    /**
     * @ORM\Column(type="string", length=128, nullable=false)
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=512, nullable=true)
     */
    private $description;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $iconPath;

    /**
     * @ORM\Column(type="string", length=32, nullable=false)
     */
    private $type;

    /**
     * @ORM\Column(type="string", length=32, nullable=false)
     */
    private $status = self::STATUS_AVAILABLE;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $createdAt;

    public function getId(): ?int { return $this->id; }
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function getIconPath(): ?string { return $this->iconPath; }
    public function setIconPath(?string $iconPath): void { $this->iconPath = $iconPath; }
    public function getType(): ?string { return $this->type; }
    public function setType(string $type): void { $this->type = $type; }
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getCreatedAt(): ?\DateTime { return $this->createdAt; }

    /**
     * @ORM\PrePersist
     */
    public function prePersist(): void
    {
        if (null === $this->createdAt) {
            $this->createdAt = new \DateTime('now');
        }
    }

    public function isAvailable(): bool
    {
        return self::STATUS_AVAILABLE === $this->status;
    }
}
