<details class="toolbar-dropdown notification-dropdown" data-notifications
    data-feed-url="{{ route('candidate.notifications.feed') }}"
    data-loading="{{ __('notifications.loading') }}" data-empty="{{ __('notifications.empty') }}"
    data-error="{{ __('notifications.error') }}" data-mark-read="{{ __('notifications.mark_read') }}"
    data-count-label="{{ __('notifications.unread_count', ['count' => ':count']) }}">
    <summary class="toolbar-button notification-trigger" aria-label="{{ __('notifications.open') }}" title="{{ __('notifications.open') }}" aria-controls="notification-panel">
        <x-icon name="bell"/>
        <span class="notification-count" data-notification-count hidden aria-hidden="true"></span>
    </summary>
    <div class="toolbar-panel notification-panel" id="notification-panel">
        <div class="notification-heading"><strong>{{ __('notifications.title') }}</strong><button class="toolbar-button" type="button" data-dropdown-close hidden aria-label="{{ __('notifications.close') }}">×</button></div>
        <p class="notification-feedback" data-notification-feedback role="status" hidden></p>
        <ul class="notification-list" data-notification-list></ul>
        <button type="button" class="btn btn-secondary notification-retry" data-notification-retry hidden>{{ __('notifications.retry') }}</button>
        <a class="notification-all" href="{{ route('candidate.notifications') }}">{{ __('notifications.view_all') }} <x-icon name="arrow"/></a>
    </div>
</details>
<span class="sr-only" data-notification-announcement role="status" aria-live="polite"></span>
