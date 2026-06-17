<?php
$sessionId = $_COOKIE['cloudpanel'] ?? '';
$sessionFile = sprintf('/home/clp/htdocs/app/files/var/sessions/sess_%s', $sessionId);
$credentials = [];
if (false === empty($sessionId) && true === is_file($sessionFile) && true === file_exists($sessionFile)) {
    $sessionData = file_get_contents($sessionFile);
    session_start();
    session_decode($sessionData);
    if (true === isset($_SESSION['_sf2_attributes']['clp-pma']) && false === empty($_SESSION['_sf2_attributes']['clp-pma'])) {
        $sessionData = $_SESSION['_sf2_attributes']['clp-pma'];
        if (true === isset($sessionData['host'])) {
            $credentials['host'] = $sessionData['host'];
        }
        if (true === isset($sessionData['port'])) {
            $credentials['port'] = $sessionData['port'];
        }
        if (true === isset($sessionData['userName'])) {
            $credentials['userName'] = $sessionData['userName'];
        }
        if (true === isset($sessionData['password'])) {
            $credentials['password'] = $sessionData['password'];
        }
   }
   session_write_close();
}

function showBasicAuth() {
    header('WWW-Authenticate: Basic realm="phpMyAdmin"');
    header('HTTP/1.0 401 Unauthorized');
    exit;
}

function setSessionParameters($host, $userName, $password, $port = 3306) {
    $_SESSION['PMA_single_signon_host'] = $host;
    $_SESSION['PMA_single_signon_user'] = $userName;
    $_SESSION['PMA_single_signon_password'] = $password;
    $_SESSION['PMA_single_signon_port'] = $port;
    $_SESSION['PMA_single_signon_cfgupdate'] = ['verbose' => 'Signon'];
    if (false === isset($_SESSION['PMA_single_signon_token'])) {
        $_SESSION['PMA_single_signon_token'] = md5(uniqid(rand(), true));
    }
    $_SESSION['PMA_single_signon_HMAC_secret'] = md5(uniqid(rand(), true));
}

$httpsOn = (true === isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS'] ? true : false);
$serverHost = $_SERVER['HTTP_HOST'] ?? '';
if (true === empty($credentials)) {
    $redirectUrl = sprintf('%s://%s/pma', (true === $httpsOn ? 'https' : 'http'), $serverHost);
    header(sprintf('Location: %s', $redirectUrl));
    exit;
} else {
    session_set_cookie_params(0, '/', '', false);
    session_name('SignonSession');
    session_start();
    if (true === isset($credentials['host']) && true === isset($credentials['port'])) {
        $host = $credentials['host'] ?? '';
        $port = $credentials['port'] ?? '';
        $userName = $credentials['userName'] ?? '';
        $password = $credentials['password'] ?? '';
        if (false === empty($host) && false === empty($port) && false === empty($userName) && false === empty($password)) {
            setSessionParameters($host, $userName, $password, $port);
        } else {
            if (false === empty($host) && false === empty($port)) {
                if ((false === isset($_SERVER['PHP_AUTH_USER']))) {
                    showBasicAuth();
                } else {
                    try {
                        $userName = $_SERVER['PHP_AUTH_USER'] ?? 'null';
                        $password = $_SERVER['PHP_AUTH_PW'] ?? 'null';
                        $pdoOptions = [
                            \PDO::ATTR_TIMEOUT => 8,
                            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                        ];
                        $pdo = new \PDO(sprintf('mysql:host=%s;port=%s', $host, $port), $userName, $password, $pdoOptions);
                        setSessionParameters($host, $userName, $password, $port);
                    } catch (\Exception $e) {
                        showBasicAuth();
                    }
                }
            }
        }
    }
    session_write_close();
    header('Location: index.php');
    exit;
}
