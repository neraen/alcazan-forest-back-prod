<?php

namespace App\DTO\Equipement\Object;

class Equipement
{
    private string $name;
    private string $description;
    private string $image;
    private int $prixRevente;
    private int $prixAchat;
    private int $levelMin;
    private int $positionEquipement;
    private int $rarity;
    private int $classe;

    private array $caracteristiques;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     */
    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    /**
     * @return string
     */
    public function getImage(): string
    {
        return $this->image;
    }

    /**
     * @param string $image
     */
    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    /**
     * @return int
     */
    public function getPrixRevente(): int
    {
        return $this->prixRevente;
    }

    /**
     * @param int $prixRevente
     */
    public function setPrixRevente(int $prixRevente): void
    {
        $this->prixRevente = $prixRevente;
    }

    /**
     * @return int
     */
    public function getPrixAchat(): int
    {
        return $this->prixAchat;
    }

    /**
     * @param int $prixAchat
     */
    public function setPrixAchat(int $prixAchat): void
    {
        $this->prixAchat = $prixAchat;
    }

    /**
     * @return int
     */
    public function getLevelMin(): int
    {
        return $this->levelMin;
    }

    /**
     * @param int $levelMin
     */
    public function setLevelMin(int $levelMin): void
    {
        $this->levelMin = $levelMin;
    }

    /**
     * @return int
     */
    public function getPositionEquipement(): int
    {
        return $this->positionEquipement;
    }

    /**
     * @param int $positionEquipement
     */
    public function setPositionEquipement(int $positionEquipement): void
    {
        $this->positionEquipement = $positionEquipement;
    }

    /**
     * @return int
     */
    public function getRarity(): int
    {
        return $this->rarity;
    }

    /**
     * @param int $rarity
     */
    public function setRarity(int $rarity): void
    {
        $this->rarity = $rarity;
    }

    /**
     * @return int
     */
    public function getClasse(): int
    {
        return $this->classe;
    }

    /**
     * @param int $classe
     */
    public function setClasse(int $classe): void
    {
        $this->classe = $classe;
    }

    /**
     * @return array
     */
    public function getCaracteristiques(): array
    {
        return $this->caracteristiques;
    }

    /**
     * @param array $caracteristiques
     */
    public function setCaracteristiques(array $caracteristiques): void
    {
        $this->caracteristiques = $caracteristiques;
    }


}