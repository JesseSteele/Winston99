function download(){
    var writtenText = document.getElementById("writingArea").value;
    var blob = new Blob([writtenText], { type: "text/plain"});
    var anchor = document.createElement("a");
//    anchor.download = "Winston-FILE.txt";
//    anchor.download = document.getElementById(saveasname).textContent;
    var saveasname = document.getElementById("saveName").value;
    anchor.download = saveasname;
    anchor.href = window.URL.createObjectURL(blob);
    anchor.target ="_blank";
    anchor.style.display = "none"; // just to be safe!
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
 }
