<div id="moodle-talk-bridge-admin" class="section">
    <h2>Moodle Talk Bridge</h2>
    <p>Configuration shared with the Moodle <code>mod_nextcloudtalk</code> plugin.</p>

    <form id="mtb-settings-form" method="post"
          action="<?php p(\OC::$server->getURLGenerator()->linkToRoute('moodle_talk_bridge.settings.save')); ?>">
        <input type="hidden" name="requesttoken"
               value="<?php p(\OC::$server->get(\OCP\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue()); ?>">

        <p>
            <label for="mtb-nextcloud-url">Nextcloud URL</label><br>
            <input type="text" id="mtb-nextcloud-url" name="nextcloud_url"
                   value="<?php p($_['nextcloud_url']); ?>" style="width:400px;">
        </p>

        <p>
            <label for="mtb-shared-secret">Shared secret</label><br>
            <input type="password" id="mtb-shared-secret" name="shared_secret"
                   placeholder="<?php p($_['has_secret'] ? '••••••••  (leave blank to keep current)' : 'Not set'); ?>"
                   style="width:400px;">
        </p>

        <p>
            <label for="mtb-bot-user">Bot username</label><br>
            <input type="text" id="mtb-bot-user" name="bot_user"
                   value="<?php p($_['bot_user']); ?>" style="width:400px;">
        </p>

        <p>
            <label for="mtb-bot-password">Bot app password</label><br>
            <input type="password" id="mtb-bot-password" name="bot_app_password"
                   placeholder="<?php p($_['has_bot_password'] ? '••••••••  (leave blank to keep current)' : 'Not set'); ?>"
                   style="width:400px;">
        </p>

        <p>
            <label for="mtb-allowed-instances">Allowed Moodle instances</label><br>
            <input type="text" id="mtb-allowed-instances" name="allowed_instances"
                   value="<?php p($_['allowed_instances']); ?>" style="width:400px;">
            <br><small>Comma-separated list of Moodle site URLs permitted to call this app.</small>
        </p>

        <p>
            <label for="mtb-moodle-host">Moodle host</label><br>
            <input type="text" id="mtb-moodle-host" name="moodle_host"
                   value="<?php p($_['moodle_host']); ?>" style="width:400px;">
        </p>

        <input type="submit" class="button primary" value="Save">
    </form>
</div>