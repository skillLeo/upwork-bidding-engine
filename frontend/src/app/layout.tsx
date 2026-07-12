import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { Toaster } from "sonner";
import "./globals.css";

const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "SkillLeo Bidding Engine",
  description: "Score, draft, and track Upwork proposals from one dashboard.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className={`${inter.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col bg-bg text-text-primary">
        {children}
        <Toaster
          position="top-right"
          toastOptions={{
            style: {
              borderRadius: "8px",
              border: "1px solid var(--color-border)",
              boxShadow: "var(--shadow-popover)",
              fontSize: "14px",
            },
          }}
        />
      </body>
    </html>
  );
}
