/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./app/**/*.{js,ts,jsx,tsx}",
    "./components/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        canvas: "#0a0908",
        surface: "#131110",
        panel: "#191614",
        raised: "#221e1a",
        line: "#2b2622",
        "line-strong": "#3d3630",
        ink: "#f6f2ea",
        muted: "#aaa29a",
        faint: "#8a827b",
        amber: {
          DEFAULT: "#f5a524",
          soft: "#ffc45c",
          deep: "#b8790b",
        },
        mint: "#8fdcb4",
        sky: "#93bdf7",
        lilac: "#d3a9f0",
        rose: "#fb7185",
      },
      fontFamily: {
        sans: ["var(--font-inter)", "ui-sans-serif", "system-ui", "sans-serif"],
        mono: ["var(--font-mono)", "ui-monospace", "SFMono-Regular", "monospace"],
      },
      keyframes: {
        rise: {
          from: { opacity: "0", transform: "translateY(8px)" },
          to: { opacity: "1", transform: "translateY(0)" },
        },
      },
      animation: {
        rise: "rise 240ms cubic-bezier(0.16, 1, 0.3, 1) both",
      },
    },
  },
  plugins: [],
};
