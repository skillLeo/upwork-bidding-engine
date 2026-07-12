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

export function OpenClawSection({
  settings,
  onSaved,
}: {
  settings: SettingsResponse["openclaw"];
  onSaved: () => void;
}) {
  const [url, setUrl] = React.useState("");
  const [token, setToken] = React.useState("");
  const [saving, setSaving] = React.useState(false);

  async function handleSave() {
    setSaving(true);
    try {
      await saveSettings({ openclaw_url: url, openclaw_token: token });
      setUrl("");
      setToken("");
      toast.success("OpenClaw settings saved.");
      onSaved();
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not save OpenClaw settings."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>OpenClaw</CardTitle>
        <TestConnectionButton service="openclaw" />
      </CardHeader>
      <CardContent className="space-y-4">
        <SecretField
          label="OpenClaw URL"
          isSet={settings.openclaw_url.is_set}
          masked={settings.openclaw_url.masked}
          value={url}
          onChange={setUrl}
          hint="Base URL, e.g. https://openclaw.example.com"
        />
        <SecretField
          label="OpenClaw token"
          isSet={settings.openclaw_token.is_set}
          masked={settings.openclaw_token.masked}
          value={token}
          onChange={setToken}
          hint="Sent as a Bearer token on every scoring/drafting request."
        />
        <div className="flex justify-end">
          <Button onClick={handleSave} loading={saving}>
            Save OpenClaw settings
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
