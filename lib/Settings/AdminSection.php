<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return 'moodle_talk_bridge'; // Must match AdminSettings::getSection()
    }

    public function getName(): string {
        return $this->l->t('Moodle Talk Bridge');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('moodle_talk_bridge', 'app.svg');
    }
}
