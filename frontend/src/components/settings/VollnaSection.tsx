"use client";

import * as React from "react";
import { toast } from "sonner";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { SecretField } from "@/components/ui/SecretField";
import { TestConnectionButton } from "./TestConnectionButton";
import { saveSettings } from "@/lib/hooks/useSettings";
import { apiErrorMessage } from "@/lib/api-client";
import type { SettingsResponse } from "@/lib/types";

export function VollnaSection({
  settings,
  onSaved,
}: {
  settings: SettingsResponse["vollna"];
  onSaved: () => void;
}) {
  const [secret, setSecret] = React.useState("");
  const [saving, setSaving] = React.useState(false);

  async function handleSave() {
    setSaving(true);
    try {
      await saveSettings({ vollna_webhook_secret: secret });
      setSecret("");
      toast.success("Vollna settings saved.");
      onSaved();
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not save Vollna settings."));
    } finally {
      setSaving(false);
    }
  }

  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? "";
  const webhookUrl = `${apiBase}/vollna-hook`;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Vollna</CardTitle>
        <TestConnectionButton service="vollna" />
      </CardHeader>
      <CardContent className="space-y-4">
        <SecretField
          testId="vollna-webhook-secret-input"
          label="Webhook secret"
          isSet={settings.vollna_webhook_secret.is_set}
          masked={settings.vollna_webhook_secret.masked}
          value={secret}
          onChange={setSecret}
          hint="Vollna must send this back in the X-Vollna-Secret header on every job."
        />
        <div className="rounded-md bg-neutral-bg p-3">
          <p className="text-xs font-medium text-text-tertiary">Point Vollna&apos;s webhook at</p>
          <p className="mt-1 font-mono text-xs break-all text-text-primary">{webhookUrl}</p>
        </div>
        <div className="flex justify-end">
          <Button onClick={handleSave} loading={saving}>
            Save Vollna settings
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
