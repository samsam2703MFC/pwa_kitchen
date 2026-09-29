<?php

function show($stuff)
{
    echo "<pre>";
    print_r($stuff);
    echo "</pre>";
}

function redirect($path)
{
    header("Location: " . ROOT. "/" . trim($path, "/"));
    die;
}

function old_value($key, $default = '')
{
    if(!empty($_POST[$key]))
        return $_POST[$key];

    return $default;
}

function old_select($key, $value, $default = '')
{

    if(!empty($_POST[$key]) && $_POST[$key] == $value)
    {
        return ' selected ';
    }

    if(!empty($default) && $default == $value)
    {
        return ' selected ';
    }

    return '';
}

function old_checkbox($key, $value, $default = '')
{
    if(!empty($_POST[$key]) && $_POST[$key] == $value)
    {
        return ' checked ';
    }

    if(!empty($default) && $default == $value)
    {
        return ' checked ';
    }

    return '';
}

function getSplittedTimestamp($timestampString){

    $timestamp = strtotime($timestampString);

    $time = date('H:i:s', $timestamp);
    $date = date('y-m-d', $timestamp);

    return [
        "date" => $date,
        "time" => $time
    ];
}

/**
 * Ładuje tłumaczenia dla całej aplikacji lub dla konkretnego modułu.
 *
 * @param string $lang - Kod języka (np. 'pl', 'en').
 * @param string|null $module - Opcjonalny moduł, dla którego mają być załadowane tłumaczenia.
 * @param string $defaultLang - Domyślny język na wypadek braku tłumaczeń (np. 'en').
 * @return array - Zwraca tablicę tłumaczeń.
 */
function loadTranslations($type, $lang, $module = null, $defaultLang = APP_FALLBACK_LANGUAGE) {
    $basePath = __DIR__ . '/../I18n/translations/'; // <- od Support do I18n
    $filePath = $basePath . $type . "/" . $lang . "/" . $module . ".json";

    // Ładowanie tłumaczeń dla danego języka
    if (file_exists($filePath)) {
        $string = file_get_contents($filePath);


        $translations = json_decode(json_encode(json_decode($string)), true);
    } else {
        $translations = [];
    }

    // Repli sur la langue par défaut.
    // Le chemin construit ici était malformé — « …/translations/ » . « fr » .
    // « orders.json » donne « …/translations/frorders.json » : ni séparateur,
    // ni segment $type. Il n'existait donc jamais, le repli ne se déclenchait
    // pas, et une langue non prise en charge renvoyait un tableau vide. Les
    // gabarits retombaient alors sur leurs valeurs polonaises codées en dur,
    // au milieu d'une interface censée être dans une autre langue.
    if (empty($translations) && $lang !== $defaultLang) {
        $fallbackPath = $basePath . $type . "/" . $defaultLang . "/"
            . ($module ?: 'messages') . ".json";
        if (file_exists($fallbackPath)) {
            $translations = json_decode(file_get_contents($fallbackPath), true) ?: [];
        }
    }

    return $translations;
}


/**
 * Langue de l'interface.
 *
 * On lit la préférence du navigateur, mais on ne la retient que si elle
 * correspond à une langue réellement traduite. L'ancienne version renvoyait
 * le code tel quel : un navigateur en « de » ou en « es » demandait un
 * dossier de traductions inexistant et l'interface se vidait de ses libellés.
 * Toute langue non couverte retombe désormais sur le français.
 */
function getUserLanguage() {
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    // « fr-FR,fr;q=0.9,en;q=0.8 » → on essaie chaque langue par ordre de
    // préférence et on garde la première qui est traduite.
    foreach (explode(',', $accept) as $part) {
        $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
        if ($code !== '' && in_array($code, APP_SUPPORTED_LANGUAGES, true)) {
            return $code;
        }
    }

    return APP_FALLBACK_LANGUAGE;
}

function sanitizeSlug(string $slug): string
{
    return strtolower(preg_replace('/[^a-z0-9-]/', '', $slug));
}

