import "../css/app.css";
// vue-sonner ships its own stylesheet — without this, every toast renders as
// raw unstyled HTML (the "messy" bottom-left alerts). This is what makes the
// position/theme/rich-colors config in App.vue actually take effect.
import "vue-sonner/style.css";
import { createApp } from "vue";
import { createPinia } from "pinia";
import router from "@/router";
import App from "@/App.vue";

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.mount("#app");

// Register the notification service worker so alerts can display on mobile
// Chrome (which can't use the `new Notification()` constructor). Best-effort.
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("/sw.js").catch(() => {});
}
