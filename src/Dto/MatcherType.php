<?php

declare(strict_types=1);

namespace App\Dto;

enum MatcherType: string
{
    case MethodCall = 'MethodCallMatcher';
    case MethodCallStatic = 'MethodCallStaticMatcher';
    case ClassName = 'ClassNameMatcher';
    case ClassConstant = 'ClassConstantMatcher';
    case Constant = 'ConstantMatcher';
    case PropertyProtected = 'PropertyProtectedMatcher';
    case PropertyPublic = 'PropertyPublicMatcher';
    case FunctionCall = 'FunctionCallMatcher';
    case MethodArgumentDropped = 'MethodArgumentDroppedMatcher';
    case MethodArgumentRequired = 'MethodArgumentRequiredMatcher';
    case MethodArgumentUnused = 'MethodArgumentUnusedMatcher';
    case ArrayDimension = 'ArrayDimensionMatcher';
    case InterfaceMethodChanged = 'InterfaceMethodChangedMatcher';
    case PropertyExistsStatic = 'PropertyExistsStaticMatcher';
}
