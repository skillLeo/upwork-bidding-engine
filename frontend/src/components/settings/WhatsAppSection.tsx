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

export function WhatsAppSection({
  settings,
  onSaved,
}: {
  settings: SettingsResponse["whatsapp"];
  onSaved: () => void;
}) {
  const [token, setToken] = React.useState("");
  const [phoneId, setPhoneId] = React.useState("");
  const [bidderNumber, setBidderNumber] = React.useState("");
  const [saving, setSaving] = React.useState(false);

  async function handleSave() {
    setSaving(true);
    try {
      await saveSettings({
        whatsapp_token: token,
        whatsapp_phone_id: phoneId,
        bidder_whatsapp: bidderNumber,
      });
      setToken("");
      setPhoneId("");
      setBidderNumber("");
      toast.success("WhatsApp settings saved.");
      onSaved();
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not save WhatsApp settings."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>WhatsApp</CardTitle>
        <TestConnectionButton service="whatsapp" />
      </CardHeader>
      <CardContent className="space-y-4">
        <SecretField
          label="Access token"
          isSet={settings.whatsapp_token.is_set}
          masked={settings.whatsapp_token.masked}
          value={token}
          onChange={setToken}
        />
        <SecretField
          label="Phone number ID"
          isSet={settings.whatsapp_phone_id.is_set}
          masked={settings.whatsapp_phone_id.masked}
          value={phoneId}
          onChange={setPhoneId}
        />
        <SecretField
          label="Bidder's WhatsApp number"
          isSet={settings.bidder_whatsapp.is_set}
          masked={settings.bidder_whatsapp.masked}
          value={bidderNumber}
          onChange={setBidderNumber}
          hint="E.164 format, e.g. +15551234567"
        />
        <div className="flex justify-end">
          <Button onClick={handleSave} loading={saving}>
            Save WhatsApp settings
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
