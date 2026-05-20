document$.subscribe(() => {
  if (typeof mermaid === "undefined") {
    return;
  }

  mermaid.initialize({
    startOnLoad: false,
    theme: "default",
    securityLevel: "loose",
  });

  document.querySelectorAll("pre.mermaid").forEach((element, index) => {
    const graphDefinition = element.textContent;
    const graphId = `mermaid-diagram-${index}`;
    const wrapper = document.createElement("div");
    wrapper.className = "mermaid";
    wrapper.textContent = graphDefinition;
    element.replaceWith(wrapper);
  });

  mermaid.run({ querySelector: ".mermaid" });
});