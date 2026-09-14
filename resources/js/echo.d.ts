import type EchoClass from 'laravel-echo';

declare global {
    interface Window {
        Echo?: EchoClass<'reverb'>;
    }
}

export {};
