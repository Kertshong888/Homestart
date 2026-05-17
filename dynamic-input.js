// Referenced from https://www.javaspring.net/blog/how-do-i-add-textboxes-dynamically-in-javascript/

// Select elements from the DOM
const textboxContainer = document.getElementById('textboxContainer');
const addButton = document.getElementById('addButton');
const DEFAULT_TEXTBOXES = 3; // Number of default textboxes
 
// Function to create a single textbox element
function createTextbox() {
    const textbox = document.createElement('input');
    textbox.type = 'text';
    textbox.id = `textbox-${textboxCount}`; // e.g., "textbox-1", "textbox-2"
    textbox.className = 'input-field'; // Apply CSS class
    textbox.placeholder = 'Enter text here...'; // Optional placeholder
    return textbox;
}
 
// Generate default textboxes on page load
function initializeDefaultTextboxes() {
    for (let i = 0; i < DEFAULT_TEXTBOXES; i++) {
        const textbox = createTextbox();
        textboxContainer.appendChild(textbox);
    }
}
 
// Add new textbox when button is clicked
function handleAddTextbox() {
    const newTextbox = createTextbox();
    textboxContainer.appendChild(newTextbox);
}
 
// Initialize defaults and set up event listener
initializeDefaultTextboxes();
addButton.addEventListener('click', handleAddTextbox);