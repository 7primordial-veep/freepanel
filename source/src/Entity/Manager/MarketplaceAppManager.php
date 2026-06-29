<?php

namespace App\Entity\Manager;

use App\Entity\MarketplaceApp;

class MarketplaceAppManager extends BaseManager
{
    /**
     * @return MarketplaceApp[]
     */
    public function findAllOrdered(): array
    {
        return $this->repository->findBy([], ['status' => 'ASC', 'name' => 'ASC']);
    }

    public function findOneBySlug(string $slug): ?MarketplaceApp
    {
        return $this->repository->findOneBy(['slug' => $slug]);
    }

    /**
     * Seed catalog idempotently. Safe to call at install time and on demand.
     */
    public function seedDefaults(): void
    {
        $defaults = [
            [
                'slug' => 'wordpress',
                'name' => 'WordPress',
                'description' => 'Most popular CMS in the world. Create blogs, sites and shops.',
                'iconPath' => '/assets/images/marketplace/wordpress.svg',
                'type' => MarketplaceApp::TYPE_WORDPRESS,
                'installerCommand' => null,
                'status' => MarketplaceApp::STATUS_AVAILABLE,
            ],
            [
                'slug' => 'ghost',
                'name' => 'Ghost',
                'description' => 'Modern Node.js publishing platform for blogs and newsletters.',
                'iconPath' => '/assets/images/marketplace/ghost.svg',
                'type' => MarketplaceApp::TYPE_GHOST,
                'installerCommand' => null,
                'status' => MarketplaceApp::STATUS_COMING_SOON,
            ],
            [
                'slug' => 'nextcloud',
                'name' => 'Nextcloud',
                'description' => 'Self-hosted productivity platform — files, calendar, contacts.',
                'iconPath' => '/assets/images/marketplace/nextcloud.svg',
                'type' => MarketplaceApp::TYPE_NEXTCLOUD,
                'installerCommand' => null,
                'status' => MarketplaceApp::STATUS_COMING_SOON,
            ],
        ];

        foreach ($defaults as $row) {
            $existing = $this->repository->findOneBy(['slug' => $row['slug']]);
            if (null !== $existing) {
                continue;
            }
            $app = new MarketplaceApp();
            $app->setSlug($row['slug']);
            $app->setName($row['name']);
            $app->setDescription($row['description']);
            $app->setIconPath($row['iconPath']);
            $app->setType($row['type']);
            $app->setInstallerCommand($row['installerCommand']);
            $app->setStatus($row['status']);
            $this->createEntity($app);
        }
    }
}
