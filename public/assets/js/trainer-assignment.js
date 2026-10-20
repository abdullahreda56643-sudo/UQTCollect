// Functions for handling trainer assignment
const assignTrainer = {
    show: function(traineeId, traineeName) {
        document.getElementById('assignTrainerModal').classList.remove('hidden');
        document.getElementById('traineeId').value = traineeId;
        document.getElementById('assignTrainerTraineeName').textContent = traineeName;
        this.loadTrainers();
    },

    close: function() {
        document.getElementById('assignTrainerModal').classList.add('hidden');
        document.getElementById('assignTrainerForm').reset();
    },

    loadTrainers: async function() {
        try {
            const response = await fetch('../api/trainers/list.php');
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'فشل في تحميل قائمة المدربين');
            }

            const select = document.getElementById('trainerId');
            select.innerHTML = '<option value="">اختر موظف/مدرب...</option>';
            
            data.trainers.forEach(trainer => {
                const option = document.createElement('option');
                option.value = trainer.id;
                option.textContent = trainer.full_name;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('خطأ في تحميل المدربين:', error);
            alert('حدث خطأ أثناء تحميل قائمة المدربين');
        }
    },

    submit: async function(event) {
        event.preventDefault();
        
        const form = event.target;
    const traineeId = form.trainee_id.value;
    const trainerId = form.trainer_id.value;
        const notes = form.notes.value;

        if (!trainerId) {
            alert('الرجاء اختيار موظف/مدرب');
            return;
        }

        try {
            const payload = {
                trainee_ids: [parseInt(traineeId, 10)],
                trainer_id: parseInt(trainerId, 10),
                notes: notes
            };

            const response = await fetch('../api/trainees/assign-trainer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'فشل في إسناد المدرب');
            }

            alert('✅ ' + data.message);
            this.close();
            
            // تحديث الصفحة لعرض التغييرات
            window.location.reload();
        } catch (error) {
            console.error('خطأ في إسناد المدرب:', error);
            alert('حدث خطأ: ' + error.message);
        }
    }
};