<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Démarrer la session de manière sécurisée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Régénérer l'ID de session pour éviter la fixation de session
if (!isset($_SESSION['csrf_token'])) {
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Erreur de sécurité. Veuillez réessayer.";
    } else {
        // Validation des données d'entrée
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validation basique
        if (empty($username) || empty($password)) {
            $error = "Tous les champs sont requis.";
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $error = "Le nom d'utilisateur doit contenir entre 3 et 50 caractères.";
        } elseif (strlen($password) < 6) {
            $error = "Le mot de passe doit contenir au moins 6 caractères.";
        } else {
            // Limitation du taux de tentatives (simple implémentation)
            $max_attempts = 5;
            $lockout_time = 900; // 15 minutes
            $attempt_key = 'login_attempts_' . $_SERVER['REMOTE_ADDR'];
            
            if (!isset($_SESSION[$attempt_key])) {
                $_SESSION[$attempt_key] = ['count' => 0, 'last_attempt' => 0];
            }
            
            $attempts = $_SESSION[$attempt_key];
            
            // Vérifier si l'utilisateur est bloqué
            if ($attempts['count'] >= $max_attempts && 
                (time() - $attempts['last_attempt']) < $lockout_time) {
                $remaining_time = $lockout_time - (time() - $attempts['last_attempt']);
                $error = "Trop de tentatives. Réessayez dans " . ceil($remaining_time / 60) . " minutes.";
            } else {
                // Réinitialiser les tentatives si le délai est écoulé
                if ((time() - $attempts['last_attempt']) >= $lockout_time) {
                    $_SESSION[$attempt_key] = ['count' => 0, 'last_attempt' => 0];
                }
                
                // Tentative de connexion
                $userId = loginUser($username, $password);
                
                if ($userId) {
                    // Connexion réussie
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $username;
                    $_SESSION['login_time'] = time();
                    
                    // Réinitialiser les tentatives
                    unset($_SESSION[$attempt_key]);
                    
                    // Régénérer l'ID de session après connexion
                    session_regenerate_id(true);
                    
                    // Redirection sécurisée
                    $redirect = $_GET['redirect'] ?? 'dashboard.php';
                    // Valider l'URL de redirection pour éviter les attaques
                    if (filter_var($redirect, FILTER_VALIDATE_URL) === false && 
                        !preg_match('/^[a-zA-Z0-9\/_.-]+\.php$/', $redirect)) {
                        $redirect = 'dashboard.php';
                    }
                    
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    // Échec de la connexion
                    $_SESSION[$attempt_key]['count']++;
                    $_SESSION[$attempt_key]['last_attempt'] = time();
                    
                    $remaining_attempts = $max_attempts - $_SESSION[$attempt_key]['count'];
                    if ($remaining_attempts > 0) {
                        $error = "Identifiants incorrects. Il vous reste $remaining_attempts tentative(s).";
                    } else {
                        $error = "Trop de tentatives. Compte bloqué temporairement.";
                    }
                }
            }
        }
    }
}

// Fonction pour échapper les données de sortie
function escape($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Mon Application</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            font-size: 16px;
        }
        .btn {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 3px;
            font-size: 16px;
            cursor: pointer;
        }
        .btn:hover {
            background-color: #0056b3;
        }
        .error {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 15px;
        }
        .success {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 15px;
        }
        .links {
            text-align: center;
            margin-top: 20px;
        }
        .links a {
            color: #007bff;
            text-decoration: none;
        }
        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Connexion</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo escape($success); ?></div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo escape($_SERVER['PHP_SELF']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION['csrf_token']); ?>">
            
            <div class="form-group">
                <label for="username">Nom d'utilisateur :</label>
                <input type="text" 
                       id="username" 
                       name="username" 
                       value="<?php echo escape($_POST['username'] ?? ''); ?>" 
                       required 
                       minlength="3" 
                       maxlength="50"
                       autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">Mot de passe :</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       required 
                       minlength="6"
                       autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn">Se connecter</button>
        </form>
        
        <div class="links">
            <p><a href="register.php">Créer un compte</a></p>
            <p><a href="forgot_password.php">Mot de passe oublié ?</a></p>
        </div>
    </div>

    <script>
        // Amélioration de l'expérience utilisateur
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const submitBtn = document.querySelector('.btn');
            
            form.addEventListener('submit', function() {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Connexion en cours...';
            });
            
            // Focus automatique sur le premier champ
            document.getElementById('username').focus();
        });
    </script>
</body>
</html>