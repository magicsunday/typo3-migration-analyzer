.. include:: /Includes.rst.txt

.. _deprecation-99999-1234567890:

=============================================================
Deprecation: #99999 - Test method has been deprecated
=============================================================

See :issue:`99999`

Description
===========

The method :php:`\TYPO3\CMS\Core\Utility\TestUtility::oldMethod()` has been
marked as deprecated. Use :php:`\TYPO3\CMS\Core\Utility\NewUtility::newMethod()`
instead.

The class :php:`\TYPO3\CMS\Core\OldClass` is also deprecated.

Impact
======

Calling :php:`\TYPO3\CMS\Core\Utility\TestUtility::oldMethod()` will trigger
a PHP :php:`E_USER_DEPRECATED` level error.

Affected installations
======================

All installations using the deprecated method.

Migration
=========

Replace :php:`\TYPO3\CMS\Core\Utility\TestUtility::oldMethod()` with
:php:`\TYPO3\CMS\Core\Utility\NewUtility::newMethod()`.

.. index:: Backend, PHP-API, FullyScanned, ext:core
