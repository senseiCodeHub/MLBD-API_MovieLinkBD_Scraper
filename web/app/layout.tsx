import type { Metadata, Viewport } from "next";
import { Inter, JetBrains_Mono } from "next/font/google";
import "./globals.css";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

const mono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-mono",
  display: "swap",
});

export const metadata: Metadata = {
  title: "MLBD Scraper — MovieLinkBD stream sources",
  description:
    "Search the whole MovieLinkBD catalogue and pull every episode's stream URL, server, quality, audio and download link.",
  applicationName: "MLBD Scraper",
  authors: [
    {
      name: "Robiul Islam",
      url: "https://github.com/senseiCodeHub",
    },
  ],
  keywords: [
    "MovieLinkBD",
    "scraper",
    "stream links",
    "FastAPI",
    "Next.js",
    "open source",
  ],
  openGraph: {
    title: "MLBD Scraper — MovieLinkBD stream sources",
    description:
      "Whole-catalogue search with per-episode stream and download links.",
    type: "website",
  },
};

export const viewport: Viewport = {
  themeColor: "#0a0908",
  colorScheme: "dark",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className={`${inter.variable} ${mono.variable}`}>
      <body className="font-sans">{children}</body>
    </html>
  );
}
