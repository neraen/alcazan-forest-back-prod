<?php

namespace App\Entity;

use App\Repository\BossSortilegeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BossSortilegeRepository::class)
 */
class BossSortilege
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Boss::class, inversedBy="bossSortileges")
     */
    private $boss;

    /**
     * @ORM\ManyToOne(targetEntity=NonPlayerSortillege::class, inversedBy="bossSortileges")
     */
    private $nonPlayerSortilege;

    /**
     * @ORM\Column(type="integer")
     */
    private $lifePercent;

    /**
     * @ORM\Column(type="integer")
     */
    private $lifePercentMin;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoss(): ?Boss
    {
        return $this->boss;
    }

    public function setBoss(?Boss $boss): self
    {
        $this->boss = $boss;

        return $this;
    }

    public function getNonPlayerSortilege(): ?NonPlayerSortillege
    {
        return $this->nonPlayerSortilege;
    }

    public function setNonPlayerSortilege(?NonPlayerSortillege $nonPlayerSortilege): self
    {
        $this->nonPlayerSortilege = $nonPlayerSortilege;

        return $this;
    }

    public function getLifePercent(): ?int
    {
        return $this->lifePercent;
    }

    public function setLifePercent(int $lifePercent): self
    {
        $this->lifePercent = $lifePercent;

        return $this;
    }

    public function getLifePercentMin(): ?int
    {
        return $this->lifePercentMin;
    }

    public function setLifePercentMin(int $lifePercentMin): self
    {
        $this->lifePercentMin = $lifePercentMin;

        return $this;
    }
}
