<section class="panel">
    <h2>{{ __('workspace.private_employer_notes') }}</h2>
    <p class="muted">{{ __('workspace.private_notes_help') }}</p>
    <form method="post" action="{{ route('employer.applications.note', $application) }}">
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $employerNote?->version ?? 0 }}">
        <label for="employer-note">{{ __('workspace.notes_label') }}</label>
        <textarea id="employer-note" name="body" rows="5" maxlength="3000" placeholder="{{ __('workspace.note_example') }}">{{ old('body', $employerNote?->body ?? '') }}</textarea>
        @error('body')<p class="field-error">{{ $message }}</p>@enderror
        <p class="muted">{{ __('workspace.note_limits') }}</p>
        <button class="btn btn-primary" type="submit">{{ __('workspace.save_note') }}</button>
    </form>
</section>
