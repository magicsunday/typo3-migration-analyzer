.. include:: /Includes.rst.txt

.. _deprecation-11113:

==============================================
Deprecation: #11113 - DeprecatedHelper removed
==============================================

See :issue:`11113`

.. index:: PHP-API, PartiallyScanned, ext:backend

Description
===========

The class :php:`\TYPO3\CMS\Backend\Helper\DeprecatedHelper` has been deprecated.

Impact
======

Using :php:`\TYPO3\CMS\Backend\Helper\DeprecatedHelper` will trigger a deprecation warning.

Migration
=========

Use :php:`\TYPO3\CMS\Backend\Helper\ModernHelper` instead of :php:`\TYPO3\CMS\Backend\Helper\DeprecatedHelper`.
