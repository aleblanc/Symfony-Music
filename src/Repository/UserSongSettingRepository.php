<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\User;
use App\Entity\UserSongSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSongSetting>
 */
class UserSongSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSongSetting::class);
    }

    public function findOneForUser(User $user, Music $song): ?UserSongSetting
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }
}
