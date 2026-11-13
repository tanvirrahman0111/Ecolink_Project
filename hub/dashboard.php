<?php
// verify_email.php sends every role to /<role>/dashboard.php; hub staff land on the Hub Desk.
require_once __DIR__ . '/../config/app.php';
redirect('/hub/desk.php');
