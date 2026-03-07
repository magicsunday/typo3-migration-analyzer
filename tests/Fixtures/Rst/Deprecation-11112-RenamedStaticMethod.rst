.. include:: /Includes.rst.txt

.. _deprecation-11112:

=====================================================
Deprecation: #11112 - Static method calculate renamed
=====================================================

See :issue:`11112`

.. index:: PHP-API, FullyScanned, ext:core

Description
===========

The method :php:`\TYPO3\CMS\Core\Service\MathService::calculate()` has been deprecated.

Impact
======

Calling :php:`\TYPO3\CMS\Core\Service\MathService::calculate()` will trigger a deprecation warning.

Migration
=========

The method :php:`\TYPO3\CMS\Core\Service\MathService::calculate()` has been renamed
to :php:`\TYPO3\CMS\Core\Service\MathService::compute()`.
