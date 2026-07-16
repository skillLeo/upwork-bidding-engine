import { ref } from "vue";

/**
 * Thin wrapper around the browser's built-in Web Speech API - free, no API
 * key, no library. Chrome-only in practice (ships as the vendor-prefixed
 * webkitSpeechRecognition), so `supported` gates the mic button entirely
 * rather than showing something that silently does nothing on Safari/Firefox.
 *
 * The transcript is handed back via the onResult callback as plain text -
 * it lands in the search box as editable text, never auto-submitted, since
 * dictation reliably mangles stack words ("Laravel" -> "level").
 */
export function useSpeechRecognition(onResult) {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const supported = !!SpeechRecognition;
  const listening = ref(false);

  let recognition = null;
  if (supported) {
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = "en-US";

    recognition.onresult = (event) => {
      const transcript = event.results[0]?.[0]?.transcript ?? "";
      onResult(transcript);
    };
    recognition.onerror = () => {
      listening.value = false;
    };
    recognition.onend = () => {
      listening.value = false;
    };
  }

  function start() {
    if (!supported || listening.value) return;
    listening.value = true;
    recognition.start();
  }

  function stop() {
    if (!supported || !listening.value) return;
    recognition.stop();
  }

  return { supported, listening, start, stop };
}
