<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity(repositoryClass=UserRepository::class)
 * @ApiResource(
 *     normalizationContext={"groups"={"users_read"}},
 *     denormalizationContext={"disable_type_enforcement"=true}
 * )
 * @UniqueEntity("email", message="Un utilisateur possède déjà cet email")
 */
class User implements UserInterface
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"users_read", "carte_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
     * @Groups({"users_read"})
     */
    private $email;

    /**
     * @ORM\Column(type="json")
     * @Groups({"users_read"})
     */
    private array $roles = [];

    /**
     * @var string The hashed password
     * @ORM\Column(type="string")
     */
    private $password;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"users_read", "carte_read"})
     */
    private $pseudo;

    /**
     * @ORM\Column(type="boolean")
     * @Groups({"users_read"})
     */
    private $isActive;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Groups({"users_read"})
     */
    private $numero;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Groups({"users_read"})
     */
    private $description;

    /**
     * @ORM\OneToMany(targetEntity=JoueurCaracteristique::class, mappedBy="user")
     * @Groups({"users_read"})
     */
    private $joueurCaracteristiques;

    /**
     * @ORM\OneToMany(targetEntity=NiveauJoueur::class, mappedBy="user")
     * @Groups({"users_read"})
     */
    private $niveauJoueur;

    /**
     * @ORM\ManyToOne(targetEntity=Guilde::class, inversedBy="users")
     * @Groups({"users_read", "carte_read"})
     */
    private $guilde;

    /**
     * @ORM\ManyToOne(targetEntity=Alignement::class, inversedBy="users")
     * @Groups({"users_read"})
     */
    private $alignement;

    /**
     * @ORM\Column(type="datetime")
     */
    private $created;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $updated;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $lastConnexion;

    /**
     * @ORM\ManyToOne(targetEntity=Classe::class, inversedBy="users", cascade={"persist"})
     * @Groups({"users_read", "carte_read"})
     */
    private $classe;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read", "carte_read"})
     */
    private $currentLife;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read", "carte_read"})
     */
    private $maxLife;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $money;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $actionPoint;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $mouvementPoint;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $currentMana;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $maxMana;

    /**
     * @ORM\ManyToOne(targetEntity=Carte::class, inversedBy="users")
     * @Groups({"users_read"})
     */
    private $map;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read", "carte_read"})
     */
    private $caseOrdonnee;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read", "carte_read"})
     */
    private $caseAbscisse;

    /**
     * @ORM\OneToOne(targetEntity=CarteCarreau::class, mappedBy="joueur", cascade={"persist", "remove"})
     */
    private $carteCarreau;

    /**
     * @ORM\OneToMany(targetEntity=JoueurDialogue::class, mappedBy="joueur")
     */
    private $joueurDialogues;

    /**
     * @ORM\OneToMany(targetEntity=UserSequence::class, mappedBy="user")
     */
    private $userSequences;

    /**
     * @ORM\OneToMany(targetEntity=UserQuete::class, mappedBy="user")
     */
    private $userQuetes;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $sexe;

    /**
     * @ORM\Column(type="integer")
     */
    private $maxPointCarac;

    /**
     * @ORM\Column(type="integer")
     */
    private $actualPointCarac;

    /**
     * @ORM\Column(type="integer")
     */
    private $restePointCarac;

    /**
     * @ORM\OneToMany(targetEntity=UserEquipement::class, mappedBy="user")
     */
    private $userEquipements;

    /**
     * @ORM\OneToMany(targetEntity=JoueurCaracteristiqueBonus::class, mappedBy="joueur")
     */
    private $joueurCaracteristiqueBonuses;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $summoningSickness;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $honneur;

    /**
     * @ORM\OneToMany(targetEntity=UserConsommable::class, mappedBy="user")
     */
    private $userConsommables;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $time_auberge;

    /**
     * @ORM\OneToMany(targetEntity=JoueurGuilde::class, mappedBy="user")
     */
    private $joueurGuildes;

    /**
     * @ORM\OneToMany(targetEntity=JoueurGrade::class, mappedBy="user")
     */
    private $joueurGrades;

    /**
     * @ORM\OneToMany(targetEntity=Message::class, mappedBy="expediteur")
     */
    private $messages;

    /**
     * @ORM\OneToMany(targetEntity=UserBoss::class, mappedBy="user")
     */
    private $userBosses;

    /**
     * @ORM\OneToMany(targetEntity=Historique::class, mappedBy="user")
     */
    private $historiques;

    /**
     * @ORM\OneToMany(targetEntity=UserBuff::class, mappedBy="user")
     */
    private $userBuffs;

    /**
     * @ORM\OneToMany(targetEntity=Friend::class, mappedBy="user1")
     */
    private $friends;

    /**
     * @ORM\OneToMany(targetEntity=UserSortilege::class, mappedBy="user")
     */
    private $userSortileges;

    /**
     * @ORM\Column(type="boolean")
     */
    private $tutorialActive;

    public function __construct()
    {
        $this->joueurCaracteristiques = new ArrayCollection();
        $this->joueurDialogues = new ArrayCollection();
        $this->userSequences = new ArrayCollection();
        $this->userQuetes = new ArrayCollection();
        $this->userEquipements = new ArrayCollection();
        $this->joueurCaracteristiqueBonuses = new ArrayCollection();
        $this->userConsommables = new ArrayCollection();
        $this->joueurGuildes = new ArrayCollection();
        $this->joueurGrades = new ArrayCollection();
        $this->messages = new ArrayCollection();
        $this->userBosses = new ArrayCollection();
        $this->historiques = new ArrayCollection();
        $this->userBuffs = new ArrayCollection();
        $this->friends = new ArrayCollection();
        $this->userSortileges = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUsername(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Returning a salt is only needed, if you are not using a modern
     * hashing algorithm (e.g. bcrypt or sodium) in your security.yaml.
     *
     * @see UserInterface
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getPseudo(): ?string
    {
        return $this->pseudo;
    }

    public function setPseudo(string $pseudo): self
    {
        $this->pseudo = $pseudo;

        return $this;
    }

    public function getIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $is_active): self
    {
        $this->isActive = $is_active;

        return $this;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection|JoueurCaracteristique[]
     */
    public function getJoueurCaracteristiques(): Collection
    {
        return $this->joueurCaracteristiques;
    }

    public function addJoueurCaracteristique(JoueurCaracteristique $joueurCaracteristique): self
    {
        if (!$this->joueurCaracteristiques->contains($joueurCaracteristique)) {
            $this->joueurCaracteristiques[] = $joueurCaracteristique;
            $joueurCaracteristique->setUser($this);
        }

        return $this;
    }

    public function removeJoueurCaracteristique(JoueurCaracteristique $joueurCaracteristique): self
    {
        if ($this->joueurCaracteristiques->removeElement($joueurCaracteristique)) {
            // set the owning side to null (unless already changed)
            if ($joueurCaracteristique->getUser() === $this) {
                $joueurCaracteristique->setUser(null);
            }
        }

        return $this;
    }

    public function getGuilde(): ?Guilde
    {
        return $this->guilde;
    }

    public function setGuilde(?Guilde $guilde): self
    {
        $this->guilde = $guilde;

        return $this;
    }

    public function getAlignement(): ?Alignement
    {
        return $this->alignement;
    }

    public function setAlignement(?Alignement $alignement): self
    {
        $this->alignement = $alignement;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getUpdated(): ?\DateTimeInterface
    {
        return $this->updated;
    }

    public function setUpdated(?\DateTimeInterface $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function getLastConnexion(): ?\DateTimeInterface
    {
        return $this->lastConnexion;
    }

    public function setLastConnexion(?\DateTimeInterface $last_connexion): self
    {
        $this->lastConnexion = $last_connexion;

        return $this;
    }

    public function getClasse(): ?Classe
    {
        return $this->classe;
    }

    public function setClasse(?Classe $classe): self
    {
        $this->classe = $classe;

        return $this;
    }

    public function getCurrentLife(): ?int
    {
        return $this->currentLife;
    }

    public function setCurrentLife(int $current_life): self
    {
        $this->currentLife = $current_life;

        return $this;
    }

    public function getMaxLife(): ?int
    {
        return $this->maxLife;
    }

    public function setMaxLife(int $max_life): self
    {
        $this->maxLife = $max_life;

        return $this;
    }

    public function getMoney(): ?int
    {
        return $this->money;
    }

    public function setMoney(int $money): self
    {
        $this->money = $money;

        return $this;
    }

    public function getActionPoint(): ?int
    {
        return $this->actionPoint;
    }

    public function setActionPoint(int $action_point): self
    {
        $this->actionPoint = $action_point;

        return $this;
    }

    public function getMouvementPoint(): ?int
    {
        return $this->mouvementPoint;
    }

    public function setMouvementPoint(int $mouvement_point): self
    {
        $this->mouvementPoint = $mouvement_point;

        return $this;
    }

    public function getCurrentMana(): ?int
    {
        return $this->currentMana;
    }

    public function setCurrentMana(int $current_mana): self
    {
        $this->currentMana = $current_mana;

        return $this;
    }

    public function getMaxMana(): ?int
    {
        return $this->maxMana;
    }

    public function setMaxMana(int $max_mana): self
    {
        $this->maxMana = $max_mana;

        return $this;
    }

    public function getMap(): ?Carte
    {
        return $this->map;
    }

    public function setMap(?Carte $map): self
    {
        $this->map = $map;

        return $this;
    }

    public function getCaseOrdonnee(): ?int
    {
        return $this->caseOrdonnee;
    }

    public function setCaseOrdonnee(int $case_ordonne): self
    {
        $this->caseOrdonnee = $case_ordonne;

        return $this;
    }

    public function getCaseAbscisse(): ?int
    {
        return $this->caseAbscisse;
    }

    public function setCaseAbscisse(int $case_abscisse): self
    {
        $this->caseAbscisse = $case_abscisse;

        return $this;
    }

    public function getCarteCarreau(): ?CarteCarreau
    {
        return $this->carteCarreau;
    }

    public function setCarteCarreau(?CarteCarreau $carteCarreau): self
    {
        // unset the owning side of the relation if necessary
        if ($carteCarreau === null && $this->carteCarreau !== null) {
            $this->carteCarreau->setJoueur(null);
        }

        // set the owning side of the relation if necessary
        if ($carteCarreau !== null && $carteCarreau->getJoueur() !== $this) {
            $carteCarreau->setJoueur($this);
        }

        $this->carteCarreau = $carteCarreau;

        return $this;
    }

    /**
     * @return Collection|JoueurDialogue[]
     */
    public function getJoueurDialogues(): Collection
    {
        return $this->joueurDialogues;
    }

    public function addJoueurDialogue(JoueurDialogue $joueurDialogue): self
    {
        if (!$this->joueurDialogues->contains($joueurDialogue)) {
            $this->joueurDialogues[] = $joueurDialogue;
            $joueurDialogue->setJoueur($this);
        }

        return $this;
    }

    public function removeJoueurDialogue(JoueurDialogue $joueurDialogue): self
    {
        if ($this->joueurDialogues->removeElement($joueurDialogue)) {
            // set the owning side to null (unless already changed)
            if ($joueurDialogue->getJoueur() === $this) {
                $joueurDialogue->setJoueur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|UserSequence[]
     */
    public function getUserSequences(): Collection
    {
        return $this->userSequences;
    }

    public function addUserSequence(UserSequence $userSequence): self
    {
        if (!$this->userSequences->contains($userSequence)) {
            $this->userSequences[] = $userSequence;
            $userSequence->setUser($this);
        }

        return $this;
    }

    public function removeUserSequence(UserSequence $userSequence): self
    {
        if ($this->userSequences->removeElement($userSequence)) {
            // set the owning side to null (unless already changed)
            if ($userSequence->getUser() === $this) {
                $userSequence->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|UserQuete[]
     */
    public function getUserQuetes(): Collection
    {
        return $this->userQuetes;
    }

    public function addUserQuete(UserQuete $userQuete): self
    {
        if (!$this->userQuetes->contains($userQuete)) {
            $this->userQuetes[] = $userQuete;
            $userQuete->setUser($this);
        }

        return $this;
    }

    public function removeUserQuete(UserQuete $userQuete): self
    {
        if ($this->userQuetes->removeElement($userQuete)) {
            // set the owning side to null (unless already changed)
            if ($userQuete->getUser() === $this) {
                $userQuete->setUser(null);
            }
        }

        return $this;
    }

    public function getSexe(): ?string
    {
        return $this->sexe;
    }

    public function setSexe(string $sexe): self
    {
        $this->sexe = $sexe;

        return $this;
    }

    public function getMaxPointCarac(): ?int
    {
        return $this->maxPointCarac;
    }

    public function setMaxPointCarac(int $maxPointCarac): self
    {
        $this->maxPointCarac = $maxPointCarac;

        return $this;
    }

    public function getActualPointCarac(): ?int
    {
        return $this->actualPointCarac;
    }

    public function setActualPointCarac(int $actualPointCarac): self
    {
        $this->actualPointCarac = $actualPointCarac;

        return $this;
    }

    public function getRestePointCarac(): ?int
    {
        return $this->restePointCarac;
    }

    public function setRestePointCarac(int $restePointCarac): self
    {
        $this->restePointCarac = $restePointCarac;

        return $this;
    }

    /**
     * @return Collection|UserEquipement[]
     */
    public function getUserEquipements(): Collection
    {
        return $this->userEquipements;
    }

    public function addUserEquipement(UserEquipement $userEquipement): self
    {
        if (!$this->userEquipements->contains($userEquipement)) {
            $this->userEquipements[] = $userEquipement;
            $userEquipement->setUser($this);
        }

        return $this;
    }

    public function removeUserEquipement(UserEquipement $userEquipement): self
    {
        if ($this->userEquipements->removeElement($userEquipement)) {
            // set the owning side to null (unless already changed)
            if ($userEquipement->getUser() === $this) {
                $userEquipement->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|JoueurCaracteristiqueBonus[]
     */
    public function getJoueurCaracteristiqueBonuses(): Collection
    {
        return $this->joueurCaracteristiqueBonuses;
    }

    public function addJoueurCaracteristiqueBonus(JoueurCaracteristiqueBonus $joueurCaracteristiqueBonus): self
    {
        if (!$this->joueurCaracteristiqueBonuses->contains($joueurCaracteristiqueBonus)) {
            $this->joueurCaracteristiqueBonuses[] = $joueurCaracteristiqueBonus;
            $joueurCaracteristiqueBonus->setJoueur($this);
        }

        return $this;
    }

    public function removeJoueurCaracteristiqueBonus(JoueurCaracteristiqueBonus $joueurCaracteristiqueBonus): self
    {
        if ($this->joueurCaracteristiqueBonuses->removeElement($joueurCaracteristiqueBonus)) {
            // set the owning side to null (unless already changed)
            if ($joueurCaracteristiqueBonus->getJoueur() === $this) {
                $joueurCaracteristiqueBonus->setJoueur(null);
            }
        }

        return $this;
    }

    public function getSummoningSickness(): ?\DateTimeInterface
    {
        return $this->summoningSickness;
    }

    public function setSummoningSickness(?\DateTimeInterface $summoningSickness): self
    {
        $this->summoningSickness = $summoningSickness;

        return $this;
    }

    public function getHonneur(): ?int
    {
        return $this->honneur;
    }

    public function setHonneur(?int $honneur): self
    {
        $this->honneur = $honneur;

        return $this;
    }

    /**
     * @return Collection|UserConsommable[]
     */
    public function getUserConsommables(): Collection
    {
        return $this->userConsommables;
    }

    public function addUserConsommable(UserConsommable $userConsommable): self
    {
        if (!$this->userConsommables->contains($userConsommable)) {
            $this->userConsommables[] = $userConsommable;
            $userConsommable->setUser($this);
        }

        return $this;
    }

    public function removeUserConsommable(UserConsommable $userConsommable): self
    {
        if ($this->userConsommables->removeElement($userConsommable)) {
            // set the owning side to null (unless already changed)
            if ($userConsommable->getUser() === $this) {
                $userConsommable->setUser(null);
            }
        }

        return $this;
    }

    public function getTimeAuberge(): ?\DateTimeInterface
    {
        return $this->time_auberge;
    }

    public function setTimeAuberge(?\DateTimeInterface $time_auberge): self
    {
        $this->time_auberge = $time_auberge;

        return $this;
    }

    /**
     * @return Collection|JoueurGuilde[]
     */
    public function getJoueurGuildes(): Collection
    {
        return $this->joueurGuildes;
    }

    public function addJoueurGuilde(JoueurGuilde $joueurGuilde): self
    {
        if (!$this->joueurGuildes->contains($joueurGuilde)) {
            $this->joueurGuildes[] = $joueurGuilde;
            $joueurGuilde->setUser($this);
        }

        return $this;
    }

    public function removeJoueurGuilde(JoueurGuilde $joueurGuilde): self
    {
        if ($this->joueurGuildes->removeElement($joueurGuilde)) {
            // set the owning side to null (unless already changed)
            if ($joueurGuilde->getUser() === $this) {
                $joueurGuilde->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|JoueurGrade[]
     */
    public function getJoueurGrades(): Collection
    {
        return $this->joueurGrades;
    }

    public function addJoueurGrade(JoueurGrade $joueurGrade): self
    {
        if (!$this->joueurGrades->contains($joueurGrade)) {
            $this->joueurGrades[] = $joueurGrade;
            $joueurGrade->setUser($this);
        }

        return $this;
    }

    public function removeJoueurGrade(JoueurGrade $joueurGrade): self
    {
        if ($this->joueurGrades->removeElement($joueurGrade)) {
            // set the owning side to null (unless already changed)
            if ($joueurGrade->getUser() === $this) {
                $joueurGrade->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Message[]
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages[] = $message;
            $message->setExpediteur($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): self
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getExpediteur() === $this) {
                $message->setExpediteur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|UserBoss[]
     */
    public function getUserBosses(): Collection
    {
        return $this->userBosses;
    }

    public function addUserBoss(UserBoss $userBoss): self
    {
        if (!$this->userBosses->contains($userBoss)) {
            $this->userBosses[] = $userBoss;
            $userBoss->setUser($this);
        }

        return $this;
    }

    public function removeUserBoss(UserBoss $userBoss): self
    {
        if ($this->userBosses->removeElement($userBoss)) {
            // set the owning side to null (unless already changed)
            if ($userBoss->getUser() === $this) {
                $userBoss->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Historique[]
     */
    public function getHistoriques(): Collection
    {
        return $this->historiques;
    }

    public function addHistorique(Historique $historique): self
    {
        if (!$this->historiques->contains($historique)) {
            $this->historiques[] = $historique;
            $historique->setUser($this);
        }

        return $this;
    }

    public function removeHistorique(Historique $historique): self
    {
        if ($this->historiques->removeElement($historique)) {
            // set the owning side to null (unless already changed)
            if ($historique->getUser() === $this) {
                $historique->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|UserBuff[]
     */
    public function getUserBuffs(): Collection
    {
        return $this->userBuffs;
    }

    public function addUserBuff(UserBuff $userBuff): self
    {
        if (!$this->userBuffs->contains($userBuff)) {
            $this->userBuffs[] = $userBuff;
            $userBuff->setUser($this);
        }

        return $this;
    }

    public function removeUserBuff(UserBuff $userBuff): self
    {
        if ($this->userBuffs->removeElement($userBuff)) {
            // set the owning side to null (unless already changed)
            if ($userBuff->getUser() === $this) {
                $userBuff->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Friend>
     */
    public function getFriends(): Collection
    {
        return $this->friends;
    }

    public function addFriend(Friend $friend): self
    {
        if (!$this->friends->contains($friend)) {
            $this->friends[] = $friend;
            $friend->setUser1($this);
        }

        return $this;
    }

    public function removeFriend(Friend $friend): self
    {
        if ($this->friends->removeElement($friend)) {
            // set the owning side to null (unless already changed)
            if ($friend->getUser1() === $this) {
                $friend->setUser1(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, UserSortilege>
     */
    public function getUserSortileges(): Collection
    {
        return $this->userSortileges;
    }

    public function addUserSortilege(UserSortilege $userSortilege): self
    {
        if (!$this->userSortileges->contains($userSortilege)) {
            $this->userSortileges[] = $userSortilege;
            $userSortilege->setUser($this);
        }

        return $this;
    }

    public function removeUserSortilege(UserSortilege $userSortilege): self
    {
        if ($this->userSortileges->removeElement($userSortilege)) {
            // set the owning side to null (unless already changed)
            if ($userSortilege->getUser() === $this) {
                $userSortilege->setUser(null);
            }
        }

        return $this;
    }

    public function getTutorialActive(): ?bool
    {
        return $this->tutorialActive;
    }

    public function setTutorialActive(bool $tutorialActive): self
    {
        $this->tutorialActive = $tutorialActive;

        return $this;
    }
}
