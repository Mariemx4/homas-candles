// Icons are decorative enhancements. A CDN error must not stop the page scripts.
const iconNames = [
  "HouseHeart", "ShoppingCart", "Store", "UserRoundPlus", "UserRoundArrowLeft",
  "UserRound", "AlignStartVertical", "AlignStartHorizontal", "X", "Search",
  "Menu", "ArrowRight", "ArrowLeft", "ArrowUpRight", "Sparkles",
  "ShieldCheck", "Languages", "ChevronDown", "Check", "CreditCard",
  "Mail", "Phone", "MapPin", "Send", "Eye", "EyeOff", "KeyRound",
  "MailQuestionMark", "SaveCheck", "Package", "LogOut", "MailCheck"
];

import("https://cdn.jsdelivr.net/npm/lucide@1.46.0/+esm")
  .then((lucide) => {
    if (typeof lucide.createIcons !== "function") return;

    const icons = Object.fromEntries(
      iconNames.filter((name) => name in lucide).map((name) => [name, lucide[name]])
    );
    lucide.createIcons({ icons });
  })
  .catch(() => {
    // Text links and the inline SVG navigation controls remain usable.
  });
