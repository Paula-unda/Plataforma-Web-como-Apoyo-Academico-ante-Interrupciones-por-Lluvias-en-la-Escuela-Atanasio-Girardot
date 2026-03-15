<?php
// /auth/protegido/includes/header_onesignal.php
if (!isset($onesignal_cargado) && isset($_SESSION['usuario_id'])): 
    $onesignal_cargado = true;
    
    // Asegurar que la constante esté definida
    if (!defined('ONESIGNAL_APP_ID')) {
        require_once __DIR__ . '/onesignal_config.php';
    }
?>
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script>
window.OneSignalDeferred = window.OneSignalDeferred || [];
OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({
        appId: "<?php echo ONESIGNAL_APP_ID; ?>",
        safari_web_id: "",
        notifyButton: {
            enable: true,
        },
        allowLocalhostAsSecureOrigin: true,
    });
    
    // Cuando el usuario se suscribe, enviamos su External ID
    OneSignal.User.addAlias("external_id", "<?php echo $_SESSION['usuario_id']; ?>");
});
</script>
<?php endif; ?>