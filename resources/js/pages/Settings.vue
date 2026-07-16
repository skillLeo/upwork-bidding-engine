<script setup>
import { useSettings } from "@/composables/useSettings";
import PageContainer from "@/components/layout/PageContainer.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import DiagnosticsSection from "@/components/settings/DiagnosticsSection.vue";
import VollnaSection from "@/components/settings/VollnaSection.vue";
import OpenClawSection from "@/components/settings/OpenClawSection.vue";
import WhatsAppSection from "@/components/settings/WhatsAppSection.vue";
import MailSection from "@/components/settings/MailSection.vue";
import RulesSection from "@/components/settings/RulesSection.vue";

const { settings, isLoading, refetch } = useSettings();
</script>

<template>
  <PageContainer v-if="isLoading || !settings" class="max-w-[760px] space-y-4">
    <Skeleton class="h-7 w-40" />
    <Skeleton v-for="i in 5" :key="i" class="h-48 w-full" />
  </PageContainer>

  <PageContainer v-else class="max-w-[760px] space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-text-primary">Settings</h1>
      <p class="text-sm text-text-secondary">
        API keys, tokens, and bidding rules — nothing here is stored in .env.
      </p>
    </div>
    <DiagnosticsSection />
    <VollnaSection :settings="settings.vollna" @saved="refetch" />
    <OpenClawSection :settings="settings.openclaw" @saved="refetch" />
    <WhatsAppSection :settings="settings.whatsapp" @saved="refetch" />
    <MailSection :settings="settings.mail" @saved="refetch" />
    <RulesSection :settings="settings.rules" @saved="refetch" />
  </PageContainer>
</template>
