<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useSettings } from "@/composables/useSettings";
import PageContainer from "@/components/layout/PageContainer.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import BrandingSection from "@/components/settings/BrandingSection.vue";
import DiagnosticsSection from "@/components/settings/DiagnosticsSection.vue";
import VollnaSection from "@/components/settings/VollnaSection.vue";
import OpenClawSection from "@/components/settings/OpenClawSection.vue";
import AiApiSection from "@/components/settings/AiApiSection.vue";
import WhatsAppSection from "@/components/settings/WhatsAppSection.vue";
import MailSection from "@/components/settings/MailSection.vue";
import RulesSection from "@/components/settings/RulesSection.vue";
import { cn } from "@/lib/utils";

const { settings, isLoading, refetch } = useSettings();

const sections = [
  { id: "branding", label: "Branding" },
  { id: "diagnostics", label: "Diagnostics" },
  { id: "vollna", label: "Vollna" },
  { id: "openclaw", label: "AI Engine" },
  { id: "ai", label: "AI Models & Prompts" },
  { id: "whatsapp", label: "WhatsApp" },
  { id: "mail", label: "Mail" },
  { id: "rules", label: "Bidding Rules" },
];

const activeSection = ref(sections[0].id);
let observer;

// The sidebar doubles as a jump-to nav AND a "where am I" indicator - on a
// page this long (the AI prompts section alone is several screens), scroll
// position alone doesn't answer that, so whichever section is in view gets
// highlighted automatically as you scroll, not just on click.
onMounted(() => {
  observer = new IntersectionObserver(
    (entries) => {
      const visible = entries.filter((e) => e.isIntersecting);
      if (visible.length === 0) return;
      visible.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      activeSection.value = visible[0].target.id;
    },
    { rootMargin: "-110px 0px -65% 0px", threshold: 0 },
  );

  sections.forEach((section) => {
    const el = document.getElementById(section.id);
    if (el) observer.observe(el);
  });
});

onBeforeUnmount(() => observer?.disconnect());

function scrollToSection(id) {
  activeSection.value = id;
  document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
}
</script>

<template>
  <PageContainer v-if="isLoading || !settings" class="max-w-[1400px]">
    <Skeleton class="mb-5 h-7 w-40" />
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[220px_1fr]">
      <Skeleton class="hidden h-64 w-full lg:block" />
      <div class="space-y-4">
        <Skeleton v-for="i in 5" :key="i" class="h-48 w-full" />
      </div>
    </div>
  </PageContainer>

  <PageContainer v-else class="max-w-[1400px]">
    <div class="mb-5">
      <h1 class="text-xl font-semibold text-text-primary">Settings</h1>
      <p class="text-sm text-text-secondary">
        API keys, tokens, and bidding rules — nothing here is stored in .env.
      </p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[220px_1fr] lg:items-start">
      <nav
        class="sticky top-16 z-10 -mx-4 flex gap-1 overflow-x-auto border-b border-border bg-bg/95 px-4 py-2 backdrop-blur lg:top-20 lg:mx-0 lg:flex-col lg:overflow-visible lg:border-0 lg:bg-transparent lg:px-0 lg:py-0"
      >
        <button
          v-for="section in sections"
          :key="section.id"
          type="button"
          @click="scrollToSection(section.id)"
          :class="
            cn(
              'shrink-0 rounded-md px-3 py-2 text-left text-sm font-medium whitespace-nowrap transition-colors',
              activeSection === section.id
                ? 'bg-primary-tint text-primary'
                : 'text-text-secondary hover:bg-black/5 hover:text-text-primary',
            )
          "
        >
          {{ section.label }}
        </button>
      </nav>

      <div class="min-w-0 space-y-4">
        <section id="branding" class="scroll-mt-32">
          <BrandingSection :settings="settings.branding" @saved="refetch" />
        </section>
        <section id="diagnostics" class="scroll-mt-32">
          <DiagnosticsSection />
        </section>
        <section id="vollna" class="scroll-mt-32">
          <VollnaSection :settings="settings.vollna" @saved="refetch" />
        </section>
        <section id="openclaw" class="scroll-mt-32">
          <OpenClawSection :settings="settings.openclaw" @saved="refetch" />
        </section>
        <section id="ai" class="scroll-mt-32">
          <AiApiSection :settings="settings.ai" @saved="refetch" />
        </section>
        <section id="whatsapp" class="scroll-mt-32">
          <WhatsAppSection :settings="settings.whatsapp" @saved="refetch" />
        </section>
        <section id="mail" class="scroll-mt-32">
          <MailSection :settings="settings.mail" @saved="refetch" />
        </section>
        <section id="rules" class="scroll-mt-32">
          <RulesSection :settings="settings.rules" @saved="refetch" />
        </section>
      </div>
    </div>
  </PageContainer>
</template>
