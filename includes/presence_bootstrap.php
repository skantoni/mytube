<?php
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}

ensureUserData();

$presence_user_id = (int) ($_SESSION['user_id'] ?? 0);
$presence_username = (string) ($_SESSION['username'] ?? '');
$presence_is_admin = function_exists('isAdminUser') ? (bool) isAdminUser() : false;
?>
<script>
window.mytubeUserId = <?php echo $presence_user_id; ?>;
window.mytubeUsername = <?php echo json_encode($presence_username, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.mytubeIsAdmin = <?php echo $presence_is_admin ? 'true' : 'false'; ?>;
</script>
<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="assets/js/presence.js"></script>