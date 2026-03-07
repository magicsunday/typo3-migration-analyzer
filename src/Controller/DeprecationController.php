<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeprecationController extends AbstractController
{
    #[Route('/deprecations', name: 'deprecation_list')]
    public function list(): Response
    {
        return $this->render('deprecation/list.html.twig');
    }
}
