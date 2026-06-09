const pptxgen = require("pptxgenjs");
const React = require("react");
const ReactDOMServer = require("react-dom/server");
const sharp = require("sharp");
const path = require("path");

// Icon imports
const { FaSearch, FaEnvelope, FaPhone, FaChartLine, FaUsers, FaRocket, FaBullseye, FaClock, FaCheckCircle, FaArrowRight, FaBuilding, FaPencilRuler, FaHardHat, FaStore, FaBrain, FaUserTie, FaChartBar, FaHandshake, FaLightbulb, FaCalendarCheck, FaStar, FaTrophy, FaCogs, FaDatabase, FaFileImport, FaFilter } = require("react-icons/fa");

// Brand colors
const CORAL = "D4725C";
const DARK = "1A1A1A";
const WHITE = "FFFFFF";
const LIGHT_BG = "FBF7F6";
const CORAL_LIGHT = "F5E6E1";
const CORAL_DARK = "B85A47";
const GRAY = "6B7280";
const LIGHT_GRAY = "F3F4F6";

// Icon helper
function renderIconSvg(IconComponent, color = "#000000", size = 256) {
  return ReactDOMServer.renderToStaticMarkup(
    React.createElement(IconComponent, { color, size: String(size) })
  );
}

async function iconToBase64Png(IconComponent, color, size = 256) {
  const svg = renderIconSvg(IconComponent, "#" + color, size);
  const pngBuffer = await sharp(Buffer.from(svg)).png().toBuffer();
  return "image/png;base64," + pngBuffer.toString("base64");
}

// Shadow factory (must create fresh each time)
const makeShadow = () => ({ type: "outer", color: "000000", blur: 8, offset: 3, angle: 135, opacity: 0.12 });
const makeCardShadow = () => ({ type: "outer", color: "000000", blur: 6, offset: 2, angle: 135, opacity: 0.10 });

async function createPresentation() {
  const pres = new pptxgen();
  pres.layout = "LAYOUT_16x9";
  pres.author = "Macktiles Australia";
  pres.title = "Macktiles Australia - Sales Intelligence Platform";

  // Pre-render all icons
  const icons = {};
  const iconList = [
    ["search", FaSearch, WHITE],
    ["envelope", FaEnvelope, WHITE],
    ["phone", FaPhone, WHITE],
    ["chart", FaChartLine, WHITE],
    ["users", FaUsers, WHITE],
    ["rocket", FaRocket, WHITE],
    ["bullseye", FaBullseye, WHITE],
    ["clock", FaClock, WHITE],
    ["check", FaCheckCircle, WHITE],
    ["arrow", FaArrowRight, CORAL],
    ["building", FaBuilding, WHITE],
    ["pencil", FaPencilRuler, WHITE],
    ["hardhat", FaHardHat, WHITE],
    ["store", FaStore, WHITE],
    ["brain", FaBrain, WHITE],
    ["userTie", FaUserTie, WHITE],
    ["chartBar", FaChartBar, WHITE],
    ["handshake", FaHandshake, WHITE],
    ["lightbulb", FaLightbulb, WHITE],
    ["calendar", FaCalendarCheck, WHITE],
    ["star", FaStar, WHITE],
    ["trophy", FaTrophy, WHITE],
    ["cogs", FaCogs, WHITE],
    ["database", FaDatabase, WHITE],
    ["import", FaFileImport, WHITE],
    ["filter", FaFilter, WHITE],
    ["searchDark", FaSearch, CORAL],
    ["envelopeDark", FaEnvelope, CORAL],
    ["phoneDark", FaPhone, CORAL],
    ["chartDark", FaChartLine, CORAL],
    ["rocketDark", FaRocket, CORAL],
    ["brainDark", FaBrain, CORAL],
    ["checkDark", FaCheckCircle, "10B981"],
    ["usersDark", FaUsers, CORAL],
    ["starDark", FaStar, CORAL],
    ["trophyDark", FaTrophy, CORAL],
    ["cogsDark", FaCogs, CORAL],
    ["handshakeDark", FaHandshake, CORAL],
    ["calendarDark", FaCalendarCheck, CORAL],
    ["lightbulbDark", FaLightbulb, CORAL],
    ["buildingDark", FaBuilding, CORAL],
    ["bullseyeDark", FaBullseye, CORAL],
    ["clockDark", FaClock, CORAL],
    ["databaseDark", FaDatabase, CORAL],
    ["importDark", FaFileImport, CORAL],
    ["filterDark", FaFilter, CORAL],
    ["arrowWhite", FaArrowRight, WHITE],
  ];

  for (const [name, icon, color] of iconList) {
    icons[name] = await iconToBase64Png(icon, color);
  }

  // Read logo
  const logoPath = path.resolve(__dirname, "../logo.png");
  const logoInversePath = path.resolve(__dirname, "../logo-inverse.png");

  // =====================================================
  // SLIDE 1: Title Slide (Dark background)
  // =====================================================
  let slide = pres.addSlide();
  slide.background = { color: DARK };

  // Coral accent bar at top
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 10, h: 0.06, fill: { color: CORAL }
  });

  // Logo
  slide.addImage({ path: logoInversePath, x: 0.8, y: 0.6, w: 2.2, h: 1.5, sizing: { type: "contain", w: 2.2, h: 1.5 } });

  // Title
  slide.addText("Sales Intelligence\nPlatform", {
    x: 0.8, y: 2.2, w: 8, h: 1.6,
    fontSize: 44, fontFace: "Arial Black", color: WHITE,
    bold: true, lineSpacingMultiple: 0.9, margin: 0
  });

  // Tagline
  slide.addText("Your AI-Powered Market Entry Weapon", {
    x: 0.8, y: 3.9, w: 8, h: 0.6,
    fontSize: 22, fontFace: "Arial", color: CORAL,
    italic: true, margin: 0
  });

  // Bottom bar
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 5.1, w: 10, h: 0.525, fill: { color: CORAL }
  });
  slide.addText("Confidential  |  Macktiles Australia Pty Ltd  |  2026", {
    x: 0.8, y: 5.1, w: 8.4, h: 0.525,
    fontSize: 11, fontFace: "Arial", color: WHITE,
    align: "center", valign: "middle", margin: 0
  });

  // =====================================================
  // SLIDE 2: The Challenge
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: WHITE };

  // Coral accent strip on left
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: CORAL }
  });

  slide.addText("THE CHALLENGE", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Breaking Into Australia's\n$4.2B Tile Market", {
    x: 0.8, y: 0.9, w: 8, h: 1.2,
    fontSize: 36, fontFace: "Arial Black", color: DARK,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  // Challenge cards
  const challenges = [
    { icon: "buildingDark", title: "Zero Brand Recognition", desc: "No established presence in the Australian construction and design market" },
    { icon: "usersDark", title: "No Existing Relationships", desc: "Need to build connections with builders, architects, and retailers from scratch" },
    { icon: "clockDark", title: "Limited Sales Bandwidth", desc: "Small team trying to cover a massive market across all Australian states" },
    { icon: "bullseyeDark", title: "Competitive Landscape", desc: "Established players with entrenched supplier relationships and brand loyalty" },
  ];

  for (let i = 0; i < challenges.length; i++) {
    const x = i < 2 ? 0.8 : 5.2;
    const y = i % 2 === 0 ? 2.4 : 3.85;

    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: 4.0, h: 1.2,
      fill: { color: LIGHT_BG },
      shadow: makeCardShadow()
    });

    slide.addImage({ data: icons[challenges[i].icon], x: x + 0.2, y: y + 0.25, w: 0.4, h: 0.4 });

    slide.addText(challenges[i].title, {
      x: x + 0.75, y: y + 0.15, w: 3.0, h: 0.35,
      fontSize: 14, fontFace: "Arial", color: DARK, bold: true, margin: 0
    });

    slide.addText(challenges[i].desc, {
      x: x + 0.75, y: y + 0.5, w: 3.0, h: 0.55,
      fontSize: 11, fontFace: "Arial", color: GRAY, margin: 0
    });
  }

  // =====================================================
  // SLIDE 3: The Solution
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: DARK };

  slide.addText("THE SOLUTION", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("AI-Powered Sales Intelligence\nThat Does The Heavy Lifting", {
    x: 0.8, y: 1.0, w: 8, h: 1.2,
    fontSize: 34, fontFace: "Arial Black", color: WHITE,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  const solutions = [
    { icon: "brain", title: "Automated Research", desc: "AI researches every prospect deeply in seconds" },
    { icon: "envelope", title: "Personalized Outreach", desc: "Emails crafted to each prospect's specific needs" },
    { icon: "phone", title: "Smart Call Scripts", desc: "Tailored scripts with objection handling" },
    { icon: "chart", title: "Pipeline Tracking", desc: "Never lose track of a single prospect" },
  ];

  for (let i = 0; i < solutions.length; i++) {
    const x = 0.8 + i * 2.25;
    const y = 2.8;

    // Icon circle
    slide.addShape(pres.shapes.OVAL, {
      x: x + 0.55, y: y, w: 0.7, h: 0.7, fill: { color: CORAL }
    });
    slide.addImage({ data: icons[solutions[i].icon], x: x + 0.7, y: y + 0.15, w: 0.4, h: 0.4 });

    slide.addText(solutions[i].title, {
      x: x, y: y + 0.9, w: 1.8, h: 0.4,
      fontSize: 13, fontFace: "Arial", color: WHITE, bold: true, align: "center", margin: 0
    });

    slide.addText(solutions[i].desc, {
      x: x, y: y + 1.3, w: 1.8, h: 0.6,
      fontSize: 10, fontFace: "Arial", color: "9CA3AF", align: "center", margin: 0
    });
  }

  // Bottom accent
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 5.325, w: 10, h: 0.3, fill: { color: CORAL }
  });

  // =====================================================
  // SLIDE 4: How It Works - 4 Step Flow
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: WHITE };

  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 10, h: 0.06, fill: { color: CORAL }
  });

  slide.addText("HOW IT WORKS", {
    x: 0.8, y: 0.3, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Four Steps to Market Domination", {
    x: 0.8, y: 0.8, w: 8, h: 0.6,
    fontSize: 32, fontFace: "Arial Black", color: DARK,
    bold: true, margin: 0
  });

  const steps = [
    { num: "01", icon: "importDark", title: "Import Leads", desc: "Upload your target list of builders, architects, developers & retailers via CSV" },
    { num: "02", icon: "brainDark", title: "AI Research", desc: "Platform automatically researches each company, identifies pain points & scores fit" },
    { num: "03", icon: "envelopeDark", title: "Smart Outreach", desc: "Generate personalized emails and call scripts tailored to each prospect" },
    { num: "04", icon: "chartDark", title: "Track & Convert", desc: "Manage pipeline, log calls, track responses, and close deals" },
  ];

  for (let i = 0; i < steps.length; i++) {
    const x = 0.5 + i * 2.35;
    const y = 1.8;

    // Number badge
    slide.addShape(pres.shapes.OVAL, {
      x: x + 0.6, y: y, w: 0.65, h: 0.65, fill: { color: CORAL }
    });
    slide.addText(steps[i].num, {
      x: x + 0.6, y: y, w: 0.65, h: 0.65,
      fontSize: 18, fontFace: "Arial Black", color: WHITE,
      align: "center", valign: "middle", margin: 0
    });

    // Icon
    slide.addImage({ data: icons[steps[i].icon], x: x + 0.65, y: y + 0.9, w: 0.55, h: 0.55 });

    // Title
    slide.addText(steps[i].title, {
      x: x, y: y + 1.6, w: 1.85, h: 0.4,
      fontSize: 14, fontFace: "Arial", color: DARK, bold: true, align: "center", margin: 0
    });

    // Description
    slide.addText(steps[i].desc, {
      x: x, y: y + 2.0, w: 1.85, h: 0.8,
      fontSize: 10, fontFace: "Arial", color: GRAY, align: "center", margin: 0
    });

    // Arrow between steps
    if (i < 3) {
      slide.addImage({ data: icons["arrow"], x: x + 2.0, y: y + 0.15, w: 0.3, h: 0.3 });
    }
  }

  // =====================================================
  // SLIDE 5: AI-Powered Research
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: LIGHT_BG };

  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: CORAL }
  });

  slide.addText("AI-POWERED RESEARCH", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Deep Intelligence on\nEvery Prospect", {
    x: 0.8, y: 0.9, w: 5.5, h: 1.0,
    fontSize: 32, fontFace: "Arial Black", color: DARK,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  // What AI researches - left column
  const researchItems = [
    { icon: "buildingDark", title: "Company Profile", desc: "Size, revenue, projects, market position" },
    { icon: "lightbulbDark", title: "Pain Points", desc: "Specific challenges this prospect faces" },
    { icon: "starDark", title: "Buying Signals", desc: "Growth indicators, new projects, expansions" },
    { icon: "filterDark", title: "Fit Scoring", desc: "Automatic qualification score with reasoning" },
  ];

  for (let i = 0; i < researchItems.length; i++) {
    const y = 2.1 + i * 0.85;

    slide.addShape(pres.shapes.RECTANGLE, {
      x: 0.8, y, w: 4.3, h: 0.7,
      fill: { color: WHITE }, shadow: makeCardShadow()
    });

    slide.addImage({ data: icons[researchItems[i].icon], x: 1.0, y: y + 0.15, w: 0.35, h: 0.35 });

    slide.addText(researchItems[i].title, {
      x: 1.5, y: y + 0.05, w: 3.3, h: 0.3,
      fontSize: 12, fontFace: "Arial", color: DARK, bold: true, margin: 0
    });

    slide.addText(researchItems[i].desc, {
      x: 1.5, y: y + 0.35, w: 3.3, h: 0.3,
      fontSize: 10, fontFace: "Arial", color: GRAY, margin: 0
    });
  }

  // Right side - big stat callout
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 5.6, y: 2.1, w: 3.8, h: 3.1,
    fill: { color: DARK }, shadow: makeShadow()
  });

  slide.addText("Research Time", {
    x: 5.6, y: 2.3, w: 3.8, h: 0.4,
    fontSize: 14, fontFace: "Arial", color: CORAL, align: "center", margin: 0
  });

  slide.addText("30 sec", {
    x: 5.6, y: 2.7, w: 3.8, h: 1.0,
    fontSize: 52, fontFace: "Arial Black", color: WHITE, align: "center", valign: "middle", margin: 0
  });

  slide.addText("per lead", {
    x: 5.6, y: 3.6, w: 3.8, h: 0.4,
    fontSize: 16, fontFace: "Arial", color: "9CA3AF", align: "center", margin: 0
  });

  slide.addText("vs. 45 minutes manual research", {
    x: 5.6, y: 4.2, w: 3.8, h: 0.4,
    fontSize: 12, fontFace: "Arial", color: CORAL, align: "center", italic: true, margin: 0
  });

  slide.addText("90x faster", {
    x: 5.6, y: 4.6, w: 3.8, h: 0.4,
    fontSize: 20, fontFace: "Arial Black", color: WHITE, align: "center", margin: 0
  });

  // =====================================================
  // SLIDE 6: Smart Cold Email Generation
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: WHITE };

  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 10, h: 0.06, fill: { color: CORAL }
  });

  slide.addText("SMART COLD EMAIL GENERATION", {
    x: 0.8, y: 0.3, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Emails That Actually\nGet Responses", {
    x: 0.8, y: 0.8, w: 8, h: 1.0,
    fontSize: 32, fontFace: "Arial Black", color: DARK,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  // Email sequence flow
  const emailStages = [
    { label: "Initial", desc: "Personalized intro referencing their projects", color: CORAL },
    { label: "Follow-up 1", desc: "Value-add with industry insights", color: CORAL_DARK },
    { label: "Follow-up 2", desc: "Case study or social proof", color: "8B4538" },
    { label: "Breakup", desc: "Final touch creating urgency", color: DARK },
  ];

  for (let i = 0; i < emailStages.length; i++) {
    const x = 0.5 + i * 2.35;
    const y = 2.0;

    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: 2.1, h: 2.0,
      fill: { color: WHITE },
      line: { color: emailStages[i].color, width: 2 }
    });

    // Colored header
    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: 2.1, h: 0.5,
      fill: { color: emailStages[i].color }
    });

    slide.addText(emailStages[i].label, {
      x, y, w: 2.1, h: 0.5,
      fontSize: 13, fontFace: "Arial", color: WHITE,
      bold: true, align: "center", valign: "middle", margin: 0
    });

    slide.addText(emailStages[i].desc, {
      x: x + 0.15, y: y + 0.7, w: 1.8, h: 0.8,
      fontSize: 11, fontFace: "Arial", color: GRAY, align: "center", margin: 0
    });

    // Arrow
    if (i < 3) {
      slide.addImage({ data: icons["arrow"], x: x + 2.15, y: y + 0.15, w: 0.2, h: 0.2 });
    }
  }

  // Key benefit callout
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0.8, y: 4.3, w: 8.4, h: 0.9,
    fill: { color: CORAL_LIGHT }
  });

  slide.addText([
    { text: "Every email references the prospect's actual projects, challenges, and industry context. ", options: { fontSize: 12, color: DARK } },
    { text: "Not generic templates.", options: { fontSize: 12, color: CORAL, bold: true } },
  ], {
    x: 1.0, y: 4.3, w: 8.0, h: 0.9,
    fontFace: "Arial", valign: "middle", margin: 0
  });

  // =====================================================
  // SLIDE 7: Call Script Intelligence
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: DARK };

  slide.addText("CALL SCRIPT INTELLIGENCE", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Every Call Starts With\nAn Unfair Advantage", {
    x: 0.8, y: 0.9, w: 5, h: 1.0,
    fontSize: 32, fontFace: "Arial Black", color: WHITE,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  const callFeatures = [
    { icon: "lightbulb", title: "Opening Hooks", desc: "Attention-grabbing openers tailored to each prospect's situation" },
    { icon: "bullseye", title: "Discovery Questions", desc: "Smart questions that uncover their tile sourcing pain points" },
    { icon: "handshake", title: "Objection Handling", desc: "Pre-built responses to common push-backs for new suppliers" },
    { icon: "star", title: "Value Propositions", desc: "Industry-specific angles on why Macktiles is the right fit" },
    { icon: "calendar", title: "Next Steps", desc: "Clear call-to-action and follow-up scheduling guidance" },
  ];

  for (let i = 0; i < callFeatures.length; i++) {
    const y = 2.1 + i * 0.65;

    // Icon circle
    slide.addShape(pres.shapes.OVAL, {
      x: 0.8, y: y + 0.05, w: 0.45, h: 0.45, fill: { color: CORAL }
    });
    slide.addImage({ data: icons[callFeatures[i].icon], x: 0.88, y: y + 0.13, w: 0.3, h: 0.3 });

    slide.addText(callFeatures[i].title, {
      x: 1.5, y: y, w: 2.5, h: 0.3,
      fontSize: 13, fontFace: "Arial", color: WHITE, bold: true, margin: 0
    });

    slide.addText(callFeatures[i].desc, {
      x: 1.5, y: y + 0.3, w: 3.5, h: 0.3,
      fontSize: 10, fontFace: "Arial", color: "9CA3AF", margin: 0
    });
  }

  // Right side quote box
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 5.8, y: 2.1, w: 3.7, h: 3.1,
    fill: { color: CORAL }
  });

  slide.addText([
    { text: '"', options: { fontSize: 60, color: WHITE, bold: true } },
  ], {
    x: 6.0, y: 2.1, w: 1, h: 0.8,
    fontFace: "Georgia", margin: 0
  });

  slide.addText("Your reps sound like they've known the prospect for years, not like they're reading from a script.", {
    x: 6.1, y: 2.7, w: 3.1, h: 1.5,
    fontSize: 14, fontFace: "Georgia", color: WHITE, italic: true, margin: 0
  });

  slide.addText("The Macktiles Advantage", {
    x: 6.1, y: 4.3, w: 3.1, h: 0.4,
    fontSize: 11, fontFace: "Arial", color: DARK, bold: true, margin: 0
  });

  // =====================================================
  // SLIDE 8: Pipeline Management
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: WHITE };

  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 0.08, h: 5.625, fill: { color: CORAL }
  });

  slide.addText("PIPELINE MANAGEMENT", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Never Lose Track of\nA Single Prospect", {
    x: 0.8, y: 0.9, w: 8, h: 1.0,
    fontSize: 32, fontFace: "Arial Black", color: DARK,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  // Pipeline stages
  const pipelineStages = [
    { label: "New", count: "150", color: "6B7280" },
    { label: "Researched", count: "120", color: CORAL },
    { label: "Email Sent", count: "95", color: CORAL_DARK },
    { label: "Call Due", count: "60", color: "B85A47" },
    { label: "Qualified", count: "35", color: "10B981" },
  ];

  // Funnel visualization
  for (let i = 0; i < pipelineStages.length; i++) {
    const stageWidth = 8.5 - i * 1.2;
    const x = (10 - stageWidth) / 2;
    const y = 2.2 + i * 0.62;

    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: stageWidth, h: 0.5,
      fill: { color: pipelineStages[i].color }
    });

    slide.addText(`${pipelineStages[i].label}  —  ${pipelineStages[i].count} leads`, {
      x, y, w: stageWidth, h: 0.5,
      fontSize: 13, fontFace: "Arial", color: WHITE,
      bold: true, align: "center", valign: "middle", margin: 0
    });
  }

  // Bottom text
  slide.addText("Full visibility. Every lead accounted for. Management can track progress in real-time.", {
    x: 1.5, y: 5.0, w: 7, h: 0.4,
    fontSize: 12, fontFace: "Arial", color: GRAY, align: "center", italic: true, margin: 0
  });

  // =====================================================
  // SLIDE 9: Impact Projections
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: LIGHT_BG };

  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 10, h: 0.06, fill: { color: CORAL }
  });

  slide.addText("IMPACT PROJECTIONS", {
    x: 0.8, y: 0.3, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("The Numbers Speak\nFor Themselves", {
    x: 0.8, y: 0.8, w: 8, h: 1.0,
    fontSize: 32, fontFace: "Arial Black", color: DARK,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  // Big stat cards
  const stats = [
    { number: "100+", label: "Leads researched\nper hour", sublabel: "vs. 2-3 manually" },
    { number: "3x", label: "Higher response\nrate", sublabel: "with personalized outreach" },
    { number: "0", label: "Leads falling\nthrough cracks", sublabel: "full pipeline visibility" },
    { number: "10x", label: "Productivity\nmultiplier", sublabel: "per sales rep" },
  ];

  for (let i = 0; i < stats.length; i++) {
    const x = 0.5 + i * 2.35;
    const y = 2.0;

    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: 2.1, h: 2.8,
      fill: { color: WHITE }, shadow: makeCardShadow()
    });

    // Coral top accent
    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: 2.1, h: 0.06, fill: { color: CORAL }
    });

    slide.addText(stats[i].number, {
      x, y: y + 0.3, w: 2.1, h: 0.8,
      fontSize: 44, fontFace: "Arial Black", color: CORAL,
      align: "center", valign: "middle", margin: 0
    });

    slide.addText(stats[i].label, {
      x: x + 0.15, y: y + 1.2, w: 1.8, h: 0.7,
      fontSize: 13, fontFace: "Arial", color: DARK, bold: true, align: "center", margin: 0
    });

    slide.addText(stats[i].sublabel, {
      x: x + 0.15, y: y + 2.0, w: 1.8, h: 0.5,
      fontSize: 10, fontFace: "Arial", color: GRAY, align: "center", italic: true, margin: 0
    });
  }

  // =====================================================
  // SLIDE 10: ROI Comparison
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: DARK };

  slide.addText("RETURN ON INVESTMENT", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Manual vs. Platform", {
    x: 0.8, y: 0.9, w: 8, h: 0.6,
    fontSize: 32, fontFace: "Arial Black", color: WHITE,
    bold: true, margin: 0
  });

  // Before / After comparison
  // LEFT: Manual
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0.5, y: 1.8, w: 4.2, h: 3.2,
    fill: { color: "2A2A2A" }
  });

  slide.addText("WITHOUT PLATFORM", {
    x: 0.5, y: 1.8, w: 4.2, h: 0.5,
    fontSize: 12, fontFace: "Arial", color: "EF4444",
    bold: true, align: "center", valign: "middle", charSpacing: 2, margin: 0
  });

  const manualItems = [
    "~20 personalized emails/day",
    "45 min research per lead",
    "Generic, copy-paste templates",
    "Leads lost in spreadsheets",
    "No follow-up tracking",
    "Management flying blind",
  ];

  for (let i = 0; i < manualItems.length; i++) {
    slide.addText("x  " + manualItems[i], {
      x: 0.8, y: 2.4 + i * 0.4, w: 3.6, h: 0.35,
      fontSize: 11, fontFace: "Arial", color: "9CA3AF", margin: 0
    });
  }

  // RIGHT: Platform
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 5.3, y: 1.8, w: 4.2, h: 3.2,
    fill: { color: CORAL }
  });

  slide.addText("WITH PLATFORM", {
    x: 5.3, y: 1.8, w: 4.2, h: 0.5,
    fontSize: 12, fontFace: "Arial", color: WHITE,
    bold: true, align: "center", valign: "middle", charSpacing: 2, margin: 0
  });

  const platformItems = [
    "200+ personalized emails/day",
    "30 seconds research per lead",
    "AI-crafted unique emails",
    "Full pipeline visibility",
    "Automated follow-up sequences",
    "Real-time dashboards & stats",
  ];

  for (let i = 0; i < platformItems.length; i++) {
    slide.addImage({ data: icons["checkDark"], x: 5.6, y: 2.45 + i * 0.4, w: 0.2, h: 0.2 });
    slide.addText(platformItems[i], {
      x: 5.9, y: 2.4 + i * 0.4, w: 3.3, h: 0.35,
      fontSize: 11, fontFace: "Arial", color: WHITE, bold: true, margin: 0
    });
  }

  // =====================================================
  // SLIDE 11: Market Entry Advantage
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: WHITE };

  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 0, w: 10, h: 0.06, fill: { color: CORAL }
  });

  slide.addText("MARKET ENTRY ADVANTAGE", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Arrive in Australia With\nIntelligence, Not Guesswork", {
    x: 0.8, y: 0.9, w: 8, h: 1.0,
    fontSize: 32, fontFace: "Arial Black", color: DARK,
    bold: true, lineSpacingMultiple: 0.95, margin: 0
  });

  // Target segments
  const segments = [
    { icon: "hardhat", title: "Builders &\nContractors", desc: "Know their current projects and tile sourcing challenges" },
    { icon: "pencil", title: "Architects &\nDesigners", desc: "Understand their design preferences and supplier frustrations" },
    { icon: "building", title: "Property\nDevelopers", desc: "Track their pipeline and volume requirements" },
    { icon: "store", title: "Tile Retailers\n& Distributors", desc: "Identify their range gaps and margin pressures" },
  ];

  for (let i = 0; i < segments.length; i++) {
    const x = 0.5 + i * 2.35;
    const y = 2.2;

    slide.addShape(pres.shapes.RECTANGLE, {
      x, y, w: 2.1, h: 2.6,
      fill: { color: LIGHT_BG }, shadow: makeCardShadow()
    });

    // Icon circle
    slide.addShape(pres.shapes.OVAL, {
      x: x + 0.7, y: y + 0.25, w: 0.7, h: 0.7, fill: { color: CORAL }
    });
    slide.addImage({ data: icons[segments[i].icon], x: x + 0.85, y: y + 0.40, w: 0.4, h: 0.4 });

    slide.addText(segments[i].title, {
      x: x + 0.15, y: y + 1.1, w: 1.8, h: 0.55,
      fontSize: 13, fontFace: "Arial", color: DARK, bold: true, align: "center", margin: 0
    });

    slide.addText(segments[i].desc, {
      x: x + 0.15, y: y + 1.7, w: 1.8, h: 0.7,
      fontSize: 10, fontFace: "Arial", color: GRAY, align: "center", margin: 0
    });
  }

  // =====================================================
  // SLIDE 12: Live Demo Agenda
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: DARK };

  slide.addText("LIVE DEMO", {
    x: 0.8, y: 0.4, w: 8, h: 0.5,
    fontSize: 14, fontFace: "Arial", color: CORAL,
    bold: true, charSpacing: 4, margin: 0
  });

  slide.addText("Let's See It In Action", {
    x: 0.8, y: 0.9, w: 8, h: 0.6,
    fontSize: 36, fontFace: "Arial Black", color: WHITE,
    bold: true, margin: 0
  });

  const demoItems = [
    { num: "1", title: "Import a Lead List", desc: "Upload CSV of target builders and architects" },
    { num: "2", title: "AI Enrichment", desc: "Watch the platform research a prospect in real-time" },
    { num: "3", title: "Email Generation", desc: "See a personalized cold email crafted instantly" },
    { num: "4", title: "Call Script", desc: "Generate a tailored call script with objection handling" },
    { num: "5", title: "Pipeline Tracking", desc: "Walk through the full lead management workflow" },
  ];

  for (let i = 0; i < demoItems.length; i++) {
    const y = 1.8 + i * 0.7;

    // Number circle
    slide.addShape(pres.shapes.OVAL, {
      x: 0.8, y: y + 0.05, w: 0.5, h: 0.5, fill: { color: CORAL }
    });
    slide.addText(demoItems[i].num, {
      x: 0.8, y: y + 0.05, w: 0.5, h: 0.5,
      fontSize: 16, fontFace: "Arial Black", color: WHITE,
      align: "center", valign: "middle", margin: 0
    });

    slide.addText(demoItems[i].title, {
      x: 1.5, y: y, w: 3, h: 0.3,
      fontSize: 15, fontFace: "Arial", color: WHITE, bold: true, margin: 0
    });

    slide.addText(demoItems[i].desc, {
      x: 1.5, y: y + 0.32, w: 5, h: 0.3,
      fontSize: 11, fontFace: "Arial", color: "9CA3AF", margin: 0
    });
  }

  // Coral bar at bottom
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 5.325, w: 10, h: 0.3, fill: { color: CORAL }
  });

  // =====================================================
  // SLIDE 13: Next Steps & CTA
  // =====================================================
  slide = pres.addSlide();
  slide.background = { color: CORAL };

  // Logo
  slide.addImage({ path: logoInversePath, x: 3.5, y: 0.4, w: 3, h: 2, sizing: { type: "contain", w: 3, h: 2 } });

  slide.addText("Let's Launch Your\nAustralian Market Assault", {
    x: 0.5, y: 2.3, w: 9, h: 1.2,
    fontSize: 36, fontFace: "Arial Black", color: WHITE,
    bold: true, align: "center", lineSpacingMultiple: 0.95, margin: 0
  });

  // Action items
  const nextSteps = [
    "Upload your first 500 target leads today",
    "Run AI enrichment on top 50 prospects",
    "Launch your first personalized email campaign this week",
  ];

  for (let i = 0; i < nextSteps.length; i++) {
    slide.addImage({ data: icons["arrowWhite"], x: 2.2, y: 3.7 + i * 0.45, w: 0.25, h: 0.25 });
    slide.addText(nextSteps[i], {
      x: 2.6, y: 3.65 + i * 0.45, w: 5.5, h: 0.4,
      fontSize: 14, fontFace: "Arial", color: WHITE, bold: true, margin: 0
    });
  }

  // Bottom
  slide.addShape(pres.shapes.RECTANGLE, {
    x: 0, y: 5.1, w: 10, h: 0.525, fill: { color: DARK }
  });
  slide.addText("macktiles.com.au  |  sales@macktiles.com.au", {
    x: 0.5, y: 5.1, w: 9, h: 0.525,
    fontSize: 13, fontFace: "Arial", color: WHITE,
    align: "center", valign: "middle", margin: 0
  });

  // Save
  const outputPath = path.resolve(__dirname, "../Macktiles_Sales_Intelligence_Demo.pptx");
  await pres.writeFile({ fileName: outputPath });
  console.log("Presentation saved to: " + outputPath);
}

createPresentation().catch(err => {
  console.error("Error:", err);
  process.exit(1);
});
