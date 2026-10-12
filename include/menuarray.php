<?php
include_once 'ifaces/view.php';

const ML_MENU_LOCATION = "location";
const ML_MENU_ENTRY = "entry";
const ML_MENU_GROUP = "group";

const ML_MENU_GROUP_ADMIN = "admin";
const ML_MENU_GROUP_SURVEYS = "surveys";
const ML_MENU_GROUP_ENDED_SURVEYS = "ended_surveys";
const ML_MENU_GROUP_REGISTER = "register";
$menuarray = [
    [ML_MENU_LOCATION => "index", ML_MENU_ENTRY => "Inicio", ML_MENU_GROUP => View::MENU_GROUP_NONE],
    [ML_MENU_LOCATION => "admin", ML_MENU_ENTRY => "Administración", ML_MENU_GROUP => ML_MENU_GROUP_ADMIN],
    [ML_MENU_LOCATION => "surveys", ML_MENU_ENTRY => "Consultas activas", ML_MENU_GROUP => ML_MENU_GROUP_SURVEYS],
    [ML_MENU_LOCATION => "ended_surveys", ML_MENU_ENTRY => "Consultas finalizadas", ML_MENU_GROUP => ML_MENU_GROUP_ENDED_SURVEYS],
    [ML_MENU_LOCATION => "register", ML_MENU_ENTRY => "Regístrate", ML_MENU_GROUP => ML_MENU_GROUP_REGISTER],
];
