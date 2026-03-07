.. include:: /Includes.rst.txt

.. _deprecation-11111:

=============================================
Deprecation: #11111 - OldUtility class renamed
=============================================

See :issue:`11111`

.. index:: PHP-API, FullyScanned, ext:core

Description
===========

The class :php:`\TYPO3\CMS\Core\Utility\OldUtility` has been deprecated.

Impact
======

Using :php:`\TYPO3\CMS\Core\Utility\OldUtility` will trigger a deprecation warning.

Migration
=========

Replace :php:`\TYPO3\CMS\Core\Utility\OldUtility` with :php:`\TYPO3\CMS\Core\Utility\NewUtility`.
