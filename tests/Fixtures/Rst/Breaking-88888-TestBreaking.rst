.. include:: /Includes.rst.txt

.. _breaking-88888:

=============================================
Breaking: #88888 - Test class has been removed
=============================================

See :issue:`88888`

Description
===========

The class :php:`\TYPO3\CMS\Core\Removed\OldService` has been removed.

The property :php:`\TYPO3\CMS\Core\DataHandling\DataHandler->$recUpdateAccessCache`
is now protected.

Impact
======

Using the removed class will cause a fatal error.

Affected installations
======================

Extensions using the removed class.

Migration
=========

Use the new :php:`\TYPO3\CMS\Core\New\NewService` class instead.

.. index:: Backend, NotScanned, ext:core
