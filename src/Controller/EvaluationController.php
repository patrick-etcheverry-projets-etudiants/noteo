<?php

namespace App\Controller;

use App\Entity\Evaluation;
use App\Entity\Etudiant;
use App\Entity\Partie;
use App\Entity\Statut;
use App\Form\PointsType;
use App\Entity\Points;
use App\Form\EvaluationType;
use App\Entity\GroupeEtudiant;
use App\Repository\EvaluationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Gedmo\Tree\Entity\Repository\NestedTreeRepository;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Repository\StatutRepository;

/**
 * @Route("/evaluation")
 */
class EvaluationController extends AbstractController
{
    /**
     * @Route("/", name="evaluation_index", methods={"GET"})
     */
    public function index(EvaluationRepository $evaluationRepository): Response
    {
        return $this->render('evaluation/index.html.twig', [
            'evaluations' => $evaluationRepository->findAll(),
        ]);
    }

    /**
     * @Route("/new/{id}", name="evaluation_new", methods={"GET","POST"})
     */
    public function new(Request $request, GroupeEtudiant $groupeConcerne, ValidatorInterface $validator): Response
    {
        //Création d'une évaluation vide avec tous ses composants (partie, notes)
        $evaluation = new Evaluation();
        $evaluation->setGroupe($groupeConcerne);
        $partie = new Partie();
        $partie->setIntitule("");
        $partie->setBareme(20);
        $evaluation->addPartie($partie);
        foreach ($groupeConcerne->getEtudiants() as $etudiant) {
          $note = new Points();
          $note->setValeur(0);
          $etudiant->addPoint($note);
          $partie->addNote($note);
        }

        $form = $this->createFormBuilder(['notes' => $partie->getNotes()])
            ->add('nom', TextType::class)
            ->add('date', DateType::class, [
              'widget' => 'single_text'
            ])
            ->add('notes', CollectionType::class , [
              'entry_type' => PointsType::class
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            $entityManager = $this->getDoctrine()->getManager();

            $data = $form->getData();

            $evaluation->setNom($data["nom"]);
            $evaluation->setDate($data["date"]);

            $this->validerEntite($evaluation, $validator);
            $this->validerEntite($partie, $validator);

            $entityManager->persist($evaluation);
            $entityManager->persist($partie);

            foreach ($partie->getNotes() as $note) {
              if (!($note->getValeur() <= $partie->getBareme())) {
                $note->setValeur($partie->getBareme());
              }
              $this->validerEntite($note, $validator);
              $entityManager->persist($note);
            }

            $entityManager->flush();
            return $this->redirectToRoute('evaluation_index');
        }

        return $this->render('evaluation/new.html.twig', [
            'evaluation' => $evaluation,
            'form' => $form->createView(),
        ]);
    }

    public function validerEntite ($entite, $validator) {
      $errors = $validator->validate($entite);

      if (count($errors) > 0) {
          $errorsString = (string) $errors;
          return new Response($errorsString);
      }
    }

    /**
     * @Route("/{id}", name="evaluation_show", methods={"GET"})
     */
    public function show(Evaluation $evaluation): Response
    {
        return $this->render('evaluation/show.html.twig', [
            'evaluation' => $evaluation,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="evaluation_edit", methods={"GET","POST"})
     */
    public function edit(Request $request, Evaluation $evaluation, ValidatorInterface $validator): Response
    {
      foreach ($evaluation->getParties() as $partie) {
        $tab = $partie->getNotes();
      }

      $form = $this->createFormBuilder(['notes' => $tab])
          ->add('nom', TextType::class, [
            'data' => $evaluation->getNom()
          ])
          ->add('date', DateType::class, [
            'widget' => 'single_text',
            'data' => $evaluation->getDateUnformatted(),
          ])
          ->add('notes', CollectionType::class , [
            'entry_type' => PointsType::class
          ])
          ->getForm();

      $form->handleRequest($request);

      if ($form->isSubmitted()) {

          $entityManager = $this->getDoctrine()->getManager();

          $data = $form->getData();

          $evaluation->setNom($data["nom"]);
          $evaluation->setDate($data["date"]);

          $this->validerEntite($evaluation, $validator);

          $entityManager->persist($evaluation);
          $entityManager->persist($partie);

          foreach ($partie->getNotes() as $note) {
            if (!($note->getValeur() <= $partie->getBareme())) {
              $note->setValeur($partie->getBareme());
            }
            $this->validerEntite($note, $validator);
            $entityManager->persist($note);
          }

          $entityManager->flush();
          return $this->redirectToRoute('evaluation_index');
      }

      return $this->render('evaluation/new.html.twig', [
          'evaluation' => $evaluation,
          'form' => $form->createView(),
      ]);
    }

    /**
     * @Route("/{id}/delete", name="evaluation_delete", methods={"GET"})
     */
    public function delete(Request $request, Evaluation $evaluation): Response
    {

        $entityManager = $this->getDoctrine()->getManager();

        //Suppression des parties associées à l'évaluation
        foreach ($evaluation->getParties() as $partie) {

          //Suppression des notes associées à la partie
          foreach ($partie->getNotes() as $note) {
            $entityManager->remove($note);
          }

          $entityManager->remove($partie);

        }

        $entityManager->remove($evaluation);
        $entityManager->flush();

        return $this->redirectToRoute('evaluation_index');
    }

    /**
     * @Route("/{idEval}/choose/{idGroupe}", name="evaluation_choose_groups", methods={"GET","POST"})
     */
    public function chooseGroups(Request $request, $idEval, $idGroupe, StatutRepository $repo, EvaluationRepository $repoEval ): Response
    {
        $evaluation = $repoEval->find($idEval);
        $groupeConcerne = $this->getDoctrine()->getRepository(GroupeEtudiant::class)->find($idGroupe);

        $choixGroupe[] = $groupeConcerne;
        foreach ($this->getDoctrine()->getRepository(GroupeEtudiant::class)->children($groupeConcerne, false) as $enfant) {
          $choixGroupe[] = $enfant;
        }

        $form = $this->createFormBuilder()
            ->add('groupes', EntityType::class, [
              'class' => GroupeEtudiant::Class, //On veut choisir des étudiants
              'choice_label' => false, // On n'affichera pas d'attribut de l'entité à côté du bouton pour aider au choix car on liste les entités nous même
              'label' => false,
              'mapped' => false, // Pour que l'attribut ne soit pas immédiatement mis en BD mais soit récupérable après validation
              'expanded' => true, // Pour avoir des cases
              'multiple' => true, // à cocher
              'choices' => $choixGroupe // On restreint le choix à la liste des étudiants du groupe passé en parametre
            ])
            ->add('statuts', EntityType::class, [
              'class' => Statut::Class, //On veut choisir des étudiants
              'choice_label' => false, // On n'affichera pas d'attribut de l'entité à côté du bouton pour aider au choix car on liste les entités nous même
              'label' => false,
              'mapped' => false, // Pour que l'attribut ne soit pas immédiatement mis en BD mais soit récupérable après validation
              'expanded' => true, // Pour avoir des cases
              'multiple' => true, // à cocher
              'choices' => $repo->findAll() // On restreint le choix à la liste des étudiants du groupe passé en parametre
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            $repoPoints = $this->getDoctrine()->getRepository(Points::class);

            $listeNotesParGroupe = array();

            $listeNotesParStatut = array();

            foreach ($form->get("groupes")->getData() as $groupe) {
              $tabPoints = $repoPoints->findByGroupe($idEval, $groupe->getId());
              $listeNotesParGroupe[$groupe->getNom()] = array("notes" => $tabPoints,
                                                              "moyenne" => $this->moyenne($tabPoints), //Ici mettre la fonction pour la moyenne
                                                              "ecart-type" => $this->ecartType($tabPoints), //Ici mettre la fonction pour l'écart type'
                                                              "minimum" => $this->minimum($tabPoints), // Ici mettre fonction pour min
                                                              "maximum" => $this->maximum($tabPoints), // Ici mettre fonction pour max
                                                              "mediane" => $this->mediane($tabPoints) //Ici mettre fonction pour médiane
                                                             );
                    }

            foreach ($form->get("statuts")->getData() as $statut) {
              $tabPoints = $repoPoints->findByGroupe($idEval, $statut->getId());
              $listeNotesParStatut[$statut->getNom()] = array("notes" =>  $tabPoints,
                                                              "moyenne" => $this->moyenne($tabPoints), //Ici mettre la fonction pour la moyenne
                                                              "ecart-type" => $this->ecartType($tabPoints), //Ici mettre la fonction pour l'écart type'
                                                              "minimum" => $this->minimum($tabPoints), // Ici mettre fonction pour min
                                                              "maximum" => $this->maximum($tabPoints), // Ici mettre fonction pour max
                                                              "mediane" => $this->mediane($tabPoints) //Ici mettre fonction pour médiane
                                                             );
            }


            return $this->render('evaluation/stats.html.twig', [
                'groupes' => $listeNotesParGroupe,
                'statuts' => $listeNotesParStatut,
                'evaluation' => $evaluation
            ]);
        }

        return $this->render('evaluation/choix_groupes.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    public function moyenne($tabPoints)
    {
      $moyenne = 0;
      $nbNotes = 0;
      foreach($tabPoints as $note)
      {
        $nbNotes++;
        $moyenne += $note->getValeur();
      }

      if($nbNotes != 0){
        $moyenne = $moyenne/$nbNotes;
      }
      else {
        $moyenne = 0;
      }

      return $moyenne;
    }

    public function ecartType($tabPoints)
    {
      $moyenne = $this->moyenne($tabPoints);
      $nbNotes = 0;
      $ecartType = 0;
      foreach($tabPoints as $note)
      {
        $ecartType = $ecartType + pow((($note->getValeur()) - $moyenne), 2);
        $nbNotes++;
      }

      if ($nbNotes != 0) {
        $ecartType = sqrt($ecartType/$nbNotes);
      }
      else {
        $ecartType = 0;
      }

      return $ecartType;
    }

    public function minimum($tabPoints)
    {
      $min = 20;
      foreach($tabPoints as $note)
      {
        if ($note->getValeur() < $min)
        {
          $min = $note->getValeur();
        }
      }
      return $min;
    }

    public function maximum($tabPoints)
    {
      $max = 0;
      foreach($tabPoints as $note)
      {
        if ($note->getValeur() > $max)
        {
          $max = $note->getValeur();
        }
      }
      return $max;
    }

    public function mediane($tabPoints)
    {
      $mediane = 0;
      $valeurs = sort($tabPoints); // On trie le tableau dans l'ordre croissant
      $nbValeurs = count($tabPoints);

      if ($nbValeurs % 2 == 1) //Si il y a un nombre impair de valeurs, la médiane vaut la valeur au milieu du tableau
      {
        $mediane = $valeurs[intval($nbValeurs/2)]->getValeur();
      }
      else //Si c'est pair, la mediane vaut la demi-somme des 2 valeurs centrales
      {
        $mediane = 0;
      }

      return $mediane;
    }

    /**
     * @Route("/stats/delete", name="evaluation_delete", methods={"GET"})
     */
    public function stats(Request $request, Evaluation $evaluation): Response
    {

        $entityManager = $this->getDoctrine()->getManager();

        //Suppression des parties associées à l'évaluation
        foreach ($evaluation->getParties() as $partie) {

          //Suppression des notes associées à la partie
          foreach ($partie->getNotes() as $note) {
            $entityManager->remove($note);
          }

          $entityManager->remove($partie);

        }

        $entityManager->remove($evaluation);
        $entityManager->flush();

        return $this->redirectToRoute('evaluation_index');
    }
}
