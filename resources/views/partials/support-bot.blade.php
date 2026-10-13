<aside class="support-widget" data-support-widget hidden
    data-endpoint="{{ route('assistant.ask') }}"
    data-timeout-ms="{{ (max(5, min(30, (int) config('assistant.timeout_seconds'))) + 10) * 1000 }}"
    data-loading="{{ __('assistant.loading') }}" data-error="{{ __('assistant.error') }}"
    data-results="{{ __('assistant.results') }}" data-you="{{ __('assistant.you') }}" data-bot="{{ __('assistant.bot') }}"
    data-onsite="{{ __('status.ONSITE') }}" data-remote="{{ __('status.REMOTE') }}" data-hybrid="{{ __('status.HYBRID') }}">
    <section id="support-panel" class="support-panel" role="dialog" aria-labelledby="support-title" hidden>
        <header class="support-heading"><h2 id="support-title">{{ __('assistant.title') }}</h2><button type="button" data-support-close aria-label="{{ __('assistant.close') }}">×</button></header>
        <p class="support-privacy">{{ __('assistant.privacy') }}</p>
        <div class="support-suggestions">
            <button type="button" class="btn btn-quiet" data-support-question="{{ __('assistant.suggest_help') }}">{{ __('assistant.suggest_help') }}</button>
            <button type="button" class="btn btn-quiet" data-support-question="{{ __('assistant.suggest_jobs') }}">{{ __('assistant.suggest_jobs') }}</button>
        </div>
        <div class="support-messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="{{ __('assistant.title') }}" tabindex="0">
            <div class="support-message" data-support-welcome>{{ __('assistant.welcome') }}</div>
        </div>
        <p class="support-status" role="status"></p>
        <form class="support-form" action="{{ route('assistant.ask') }}" method="post">
            @csrf
            <label class="sr-only" for="support-question">{{ __('assistant.input') }}</label>
            <textarea id="support-question" name="message" rows="2" maxlength="1200" required placeholder="{{ __('assistant.placeholder') }}" aria-describedby="support-disclaimer"></textarea>
            <div class="support-form-actions"><button type="button" class="btn btn-quiet" data-support-clear>{{ __('assistant.clear') }}</button><button type="submit" class="btn btn-primary">{{ __('assistant.send') }} <span aria-hidden="true">↗</span></button></div>
        </form>
        <p class="support-hint" id="support-disclaimer">{{ __('assistant.disclaimer') }} <a href="{{ route('jobs.index') }}">{{ __('ui.jobs') }}</a></p>
    </section>
    <button type="button" class="support-launcher" aria-controls="support-panel" aria-expanded="false" aria-label="{{ __('assistant.open') }}"><x-icon name="message"/><span>{{ __('assistant.title') }}</span></button>
</aside>
<script src="{{ asset('support-bot.js') }}?v={{ filemtime(public_path('support-bot.js')) }}" defer></script>
