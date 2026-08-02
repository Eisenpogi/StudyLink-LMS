<?php

function study_icon($name, $class = '', $label = '')
{
    $name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $name));
    $class = trim('study-icon ' . preg_replace('/[^a-zA-Z0-9 _-]/', '', (string) $class));
    $aria = $label !== ''
        ? ' role="img" aria-label="' . e($label) . '"'
        : ' aria-hidden="true"';

    return '<svg class="' . e($class) . '"' . $aria . '>'
        . '<use href="/studyLink/assets/icons/bootstrap-icons.svg#' . e($name) . '"></use>'
        . '</svg>';
}
