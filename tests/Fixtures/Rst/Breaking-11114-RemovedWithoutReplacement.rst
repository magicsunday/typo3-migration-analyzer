.. include:: /Includes.rst.txt

.. _breaking-11114:

==============================================
Breaking: #11114 - LegacyRenderer removed
==============================================

See :issue:`11114`

.. index:: PHP-API, NotScanned, ext:fluid

Description
===========

The class :php:`\TYPO3\CMS\Fluid\View\LegacyRenderer` and its method
:php:`\TYPO3\CMS\Fluid\View\LegacyRenderer->renderSection()` have been removed.

Impact
======

Calling these APIs will result in a fatal error.

Migration
=========

There is no direct replacement. Implement custom rendering logic using
the new Fluid API.
