<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MatcherController extends AbstractController
{
    #[Route('/matcher-analysis', name: 'matcher_analysis')]
    public function analysis(): Response
    {
        return $this->render('matcher/analysis.html.twig');
    }
}
